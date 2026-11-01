<?php

// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

// Runs a copy of the built site (output/) in the PHP built-in web server and
// talks to it like a browser: cookies, CSRF token, AJAX components and forms.
// Build first: fabricare make

require_once(__DIR__ . "/harness.php");

class TestSite
{
    public $root = null;
    public $port = null;
    protected $process = null;
    protected static $sites = [];

    // Copy output/ (or ANDORA_TEST_OUTPUT) to a temporary folder, without configuration, logs and database
    public function __construct()
    {
        $output = getenv("ANDORA_TEST_OUTPUT");
        if (($output === false) || (strlen($output) == 0)) {
            $output = __DIR__ . "/../../output";
        }
        $output = realpath($output);
        if (($output === false) || !is_file($output . "/index.php") || !is_file($output . "/_site/xyo/web/web.php")) {
            echo "output/ is not built, run: fabricare make\n";
            exit(1);
        }

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "andora-test-" . bin2hex(random_bytes(6));
        self::copyDir($output, $this->root);
        foreach (glob($this->root . "/config*.php") as $file) {
            unlink($file);
        }
        foreach (glob($this->root . "/_log/*.log") as $file) {
            unlink($file);
        }
        foreach (glob($this->root . "/_repository/*.sqlite*") as $file) {
            unlink($file);
        }

        self::$sites[] = $this;
        if (count(self::$sites) == 1) {
            register_shutdown_function(function () {
                foreach (TestSite::allSites() as $site) {
                    $site->stop();
                }
            });
        }
    }

    public static function allSites()
    {
        return self::$sites;
    }

    public static function temporaryFolders()
    {
        $list = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "andora-test-*", GLOB_ONLYDIR);
        return ($list === false) ? [] : $list;
    }

    public static function copyDir($from, $to)
    {
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (scandir($from) as $name) {
            if (($name === ".") || ($name === "..")) {
                continue;
            }
            $source = $from . "/" . $name;
            if (is_dir($source)) {
                self::copyDir($source, $to . "/" . $name);
                continue;
            }
            copy($source, $to . "/" . $name);
        }
    }

    public static function removeDir($path)
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $name) {
            if (($name === ".") || ($name === "..")) {
                continue;
            }
            $item = $path . "/" . $name;
            if (is_dir($item) && !is_link($item)) {
                self::removeDir($item);
                continue;
            }
            @unlink($item);
        }
        @rmdir($path);
    }

    public function start()
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = intval(substr($name, strrpos($name, ":") + 1));

        $command = [PHP_BINARY, "-S", "127.0.0.1:" . $this->port, "-t", $this->root, __DIR__ . "/router.php"];
        $null = (DIRECTORY_SEPARATOR === "\\") ? "NUL" : "/dev/null";
        $this->process = proc_open($command, [0 => ["file", $null, "r"], 1 => ["file", $null, "w"], 2 => ["file", $null, "w"]], $pipes, $this->root);

        for ($k = 0; $k < 100; $k++) {
            $connection = @fsockopen("127.0.0.1", $this->port, $errno, $errstr, 0.1);
            if ($connection) {
                fclose($connection);
                return $this;
            }
            usleep(100000);
        }
        echo "Unable to start the PHP built-in web server\n";
        exit(1);
    }

    public function stop()
    {
        if (!is_null($this->process)) {
            $status = proc_get_status($this->process);
            if ($status["running"]) {
                if (DIRECTORY_SEPARATOR === "\\") {
                    exec("taskkill /F /T /PID " . $status["pid"] . " >NUL 2>NUL");
                } else {
                    proc_terminate($this->process);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        if (!is_null($this->root)) {
            // the server may need a moment to release the files
            for ($k = 0; $k < 20 && is_dir($this->root); $k++) {
                self::removeDir($this->root);
                if (is_dir($this->root)) {
                    usleep(100000);
                }
            }
            $this->root = null;
        }
    }

    public function url($path = "")
    {
        return "http://127.0.0.1:" . $this->port . "/" . ltrim($path, "/");
    }

    public function browser()
    {
        return new TestBrowser($this);
    }

    // Content of _log/<date>.router.log (the 501 errors with stack trace)
    public function routerLog()
    {
        $content = "";
        foreach (glob($this->root . "/_log/*.router.log") as $file) {
            $content .= file_get_contents($file);
        }
        return $content;
    }

    public function config($name)
    {
        $file = $this->root . "/" . $name;
        return is_file($file) ? file_get_contents($file) : null;
    }

    public function db()
    {
        $pdo = new PDO("sqlite:" . $this->root . "/_repository/andora.sqlite");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    public function userByEmail($email)
    {
        $statement = $this->db()->prepare("SELECT * FROM \"andora-web-user\" WHERE \"email\" = ?");
        $statement->execute([$email]);
        return $statement->fetch();
    }

    // Run the setup wizard: language, SQLite database, administrator account
    public function setup($name, $email, $password)
    {
        $browser = $this->browser();
        $page = $browser->get("setup");
        $browser->submit("setup", $page->body, "container.select-language_form", ["language" => "en-US", "action" => "submit"]);

        $form = $browser->component("setup", "container", ["component" => "select-database"]);
        $browser->submit("setup", $form->body, "container.select-database_form", ["databaseType" => "sqlite", "action" => "submit"]);

        $form = $browser->component("setup", "container", ["component" => "register-user"]);
        return $browser->submit("setup", $form->body, "container.register-user_form", [
            "name" => $name,
            "email" => $email,
            "password" => $password,
            "passwordConfirmation" => $password
        ]);
    }
}

class TestResponse
{
    public $code = 0;
    public $body = "";
    public $headers = [];

    public function header($name)
    {
        $name = strtolower($name);
        return array_key_exists($name, $this->headers) ? $this->headers[$name] : null;
    }
}

class TestBrowser
{
    protected $site = null;
    protected $cookieFile = null;
    public $token = null;
    public $tab = "test";

    public function __construct($site)
    {
        $this->site = $site;
        $this->cookieFile = $site->root . "/_log/cookies-" . bin2hex(random_bytes(4)) . ".txt";
    }

    public function request($method, $path, $query = [], $fields = null)
    {
        $url = $this->site->url($path);
        if (count($query) > 0) {
            $url .= "?" . http_build_query($query);
        }
        $response = new TestResponse();
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($curl, $line) use ($response) {
            $parts = explode(":", $line, 2);
            if (count($parts) == 2) {
                $response->headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });
        if ($method === "POST") {
            curl_setopt($curl, CURLOPT_POST, true);
            $isMultipart = false;
            foreach ((is_null($fields) ? [] : $fields) as $value) {
                if ($value instanceof CURLFile) {
                    $isMultipart = true;
                }
            }
            // multipart/form-data when a field is a file (CURLFile)
            curl_setopt($curl, CURLOPT_POSTFIELDS, $isMultipart ? $fields : http_build_query(is_null($fields) ? [] : $fields));
        }
        $response->body = (string) curl_exec($curl);
        $response->code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // the cookie jar is written when the handle is released
        unset($curl);
        $this->readToken($response->body);
        return $response;
    }

    public function get($path, $query = [])
    {
        return $this->request("GET", $path, $query);
    }

    public function post($path, $fields, $query = [])
    {
        return $this->request("POST", $path, $query, $fields);
    }

    // The CSRF token is written in the AJAX calls of the page
    public function readToken($html)
    {
        if (preg_match("/,\\s*\"([0-9a-f]{64})\"\\);/", $html, $match)) {
            $this->token = $match[1];
        }
    }

    // Request id of the AJAX form with the given id: XYO.Web.Component.AJAX.postForm("<request id>", "<form id>", ...)
    public static function formRequestId($html, $formId)
    {
        $pattern = "/XYO\\.Web\\.Component\\.AJAX\\.postForm\\((\"[^\"]*\")\\s*,\\s*" . preg_quote(json_encode($formId), "/") . "/";
        if (preg_match($pattern, $html, $match)) {
            return json_decode($match[1]);
        }
        return null;
    }

    // GET only one component, like XYO.Web.Component.AJAX.get
    public function component($path, $requestId, $payload = [])
    {
        return $this->get($path, array_merge($payload, ["_component" => $requestId, "_ajax" => 1, "_tab" => $this->tab]));
    }

    // POST to one component, like XYO.Web.Component.AJAX.post
    public function postComponent($path, $requestId, $payload = [], $query = [])
    {
        return $this->post($path, array_merge($payload, [
            "_component" => $requestId,
            "_ajax" => 1,
            "_token" => $this->token,
            "_tab" => $this->tab
        ]), $query);
    }

    // Submit an AJAX form found in $html, like XYO.Web.Component.AJAX.postForm
    public function submit($path, $html, $formId, $fields, $query = [])
    {
        $requestId = self::formRequestId($html, $formId);
        check("form " . $formId . " found on " . $path, !is_null($requestId));
        if (is_null($requestId)) {
            return new TestResponse();
        }
        return $this->postComponent($path, $requestId, $fields, $query);
    }

    // Submit an AJAX form with a file input, $file is sent as the field $fileField
    public function submitFile($path, $html, $formId, $fields, $fileField, $file)
    {
        $fields[$fileField] = new CURLFile($file, "application/zip", basename($file));
        return $this->submit($path, $html, $formId, $fields);
    }

    public function login($email, $password)
    {
        $page = $this->get("user/login");
        return $this->submit("user/login", $page->body, "form_form", ["email" => $email, "password" => $password]);
    }
}

// A second PHP built-in web server for a test: serves $root with the router
// script $router (a package registry, ...). Stopped at the end of the test;
// its folder is not removed.
class TestServer
{
    public $root = null;
    public $port = null;
    protected $process = null;

    public function __construct($root, $router)
    {
        $this->root = $root;
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = intval(substr($name, strrpos($name, ":") + 1));
        $command = [PHP_BINARY, "-S", "127.0.0.1:" . $this->port, "-t", $root, $router];
        $null = (DIRECTORY_SEPARATOR === "\\") ? "NUL" : "/dev/null";
        $this->process = proc_open($command, [0 => ["file", $null, "r"], 1 => ["file", $null, "w"], 2 => ["file", $null, "w"]], $pipes, $root);
        for ($k = 0; $k < 100; $k++) {
            $connection = @fsockopen("127.0.0.1", $this->port, $errno, $errstr, 0.1);
            if ($connection) {
                fclose($connection);
                break;
            }
            usleep(100000);
        }
        register_shutdown_function(function () {
            $this->stop();
        });
    }

    public function url($path = "")
    {
        return "http://127.0.0.1:" . $this->port . "/" . ltrim($path, "/");
    }

    public function stop()
    {
        if (is_null($this->process)) {
            return;
        }
        $status = proc_get_status($this->process);
        if ($status["running"]) {
            if (DIRECTORY_SEPARATOR === "\\") {
                exec("taskkill /F /T /PID " . $status["pid"] . " >NUL 2>NUL");
            } else {
                proc_terminate($this->process);
            }
        }
        proc_close($this->process);
        $this->process = null;
    }
}
