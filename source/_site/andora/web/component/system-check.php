<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Component;

defined("ANDORA") or die("Forbidden");

use \XYO\LucideIcons\LucideIcons;
use \Andora\Web\Model\SystemCheck as Check;

// The result of \Andora\Web\Model\SystemCheck.
// Options:
//   mode - "setup": one card, the requirements, the optional extensions and
//          the main limits (the first step of the setup);
//          "admin": a card for each part, the server, the database and the
//          logs too (admin/system)
class SystemCheck extends \XYO\Web\Component
{
    protected $mode = "setup";

    public function init($options = null)
    {
        $this->mode = "setup";
        if (is_array($options) && array_key_exists("mode", $options)) {
            $this->mode = ($options["mode"] === "admin") ? "admin" : "setup";
        }
        LucideIcons::register($this, "icons");
    }

    protected function text($key)
    {
        return $this->language->get("system-check." . $key);
    }

    protected function note($row)
    {
        if (strlen($row["note"]) == 0) {
            return "";
        }
        return str_replace(
            array("{value}", "{limit}"),
            array($row["value"], $row["limit"]),
            $this->text($row["note"])
        );
    }

    protected static $statusIcon = array(
        Check::STATUS_OK => array("check", "text-success"),
        Check::STATUS_ERROR => array("x", "text-error"),
        Check::STATUS_WARNING => array("triangle-alert", "text-warning"),
        Check::STATUS_MISSING => array("minus", "text-base-content/50"),
        Check::STATUS_INFO => array("info", "text-base-content/40")
    );

    protected function renderStatus($status)
    {
        $icon = self::$statusIcon[$status];
        echo "<span class=\"inline-flex\" title=\"" . htmlspecialchars($this->text("status." . $status), ENT_QUOTES) . "\" data-status=\"" . $status . "\">";
        $this->renderComponent("icons", array("icon" => $icon[0], "class" => "text-lg " . $icon[1]));
        echo "</span>";
    }

    // name | value | status, the note below the name
    protected function renderTable($id, $list)
    {
        if (count($list) == 0) {
            return;
        }
        ?>
        <table class="table table-sm" id="<?php echo $id; ?>">
            <tbody>
                <?php foreach ($list as $row) {
                    $note = $this->note($row); ?>
                    <tr data-name="<?php echo htmlspecialchars($row["name"], ENT_QUOTES); ?>">
                        <td class="align-top w-8 px-1"><?php $this->renderStatus($row["status"]); ?></td>
                        <td class="align-top">
                            <div class="font-mono text-sm"><?php echo htmlspecialchars($row["name"], ENT_QUOTES); ?></div>
                            <?php if (strlen($note)) { ?>
                                <div class="text-xs <?php echo ($row["status"] == Check::STATUS_ERROR) ? "text-error" : (($row["status"] == Check::STATUS_WARNING) ? "text-warning" : "text-base-content/60"); ?>"><?php echo htmlspecialchars($note, ENT_QUOTES); ?></div>
                            <?php } ?>
                        </td>
                        <td class="align-top font-mono text-sm <?php echo (strlen($row["value"]) > 16) ? "break-all" : "whitespace-nowrap"; ?>"><?php echo htmlspecialchars($row["value"], ENT_QUOTES); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php
    }

    // The extensions as badges, the notes of the missing ones below
    protected function renderBadges($id, $list)
    {
        $badgeClass = array(
            Check::STATUS_OK => "badge-success",
            Check::STATUS_ERROR => "badge-error",
            Check::STATUS_MISSING => "badge-ghost opacity-60 line-through"
        );
        ?>
        <div class="flex flex-wrap gap-1" id="<?php echo $id; ?>">
            <?php foreach ($list as $row) { ?>
                <span class="badge badge-sm font-mono <?php echo $badgeClass[$row["status"]] ?? "badge-warning"; ?>" data-name="<?php echo htmlspecialchars($row["name"], ENT_QUOTES); ?>" data-status="<?php echo $row["status"]; ?>" title="<?php echo htmlspecialchars($this->note($row), ENT_QUOTES); ?>"><?php echo htmlspecialchars($row["name"], ENT_QUOTES); ?></span>
            <?php } ?>
        </div>
        <ul class="text-xs mt-1 flex flex-col gap-0.5">
            <?php foreach ($list as $row) {
                if ($row["status"] == Check::STATUS_OK) {
                    continue;
                } ?>
                <li class="<?php echo ($row["status"] == Check::STATUS_ERROR) ? "text-error" : "text-base-content/60"; ?>">
                    <span class="font-mono"><?php echo htmlspecialchars($row["name"], ENT_QUOTES); ?></span>: <?php echo htmlspecialchars($this->note($row), ENT_QUOTES); ?>
                </li>
            <?php } ?>
        </ul>
        <?php
    }

    // The web server must not serve _repository/ and _log/ (.htaccess):
    // checked from the browser, a 200 means anyone can download the
    // database and the logs
    protected function renderProtectionCheck()
    {
        $id = $this->id . "_protection";
        $list = array("_repository/index.html", "_log/index.php");
        ?>
        <table class="table table-sm" id="<?php echo $id; ?>">
            <tbody>
                <?php foreach ($list as $index => $path) { ?>
                    <tr data-name="<?php echo htmlspecialchars($path, ENT_QUOTES); ?>">
                        <td class="align-top w-8 px-1">
                            <span data-status="checking"><span class="loading loading-spinner loading-xs"></span></span>
                            <span class="hidden" data-result="ok"><?php $this->renderStatus(Check::STATUS_OK); ?></span>
                            <span class="hidden" data-result="error"><?php $this->renderStatus(Check::STATUS_ERROR); ?></span>
                        </td>
                        <td class="align-top">
                            <div class="font-mono text-sm"><?php echo htmlspecialchars($path, ENT_QUOTES); ?></div>
                            <div class="text-xs text-error hidden" data-note="error"><?php echo htmlspecialchars($this->text("protection.exposed"), ENT_QUOTES); ?></div>
                        </td>
                        <td class="align-top font-mono text-sm" data-value></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php
        $this->view->renderJS(function () use ($id, $list) {
            echo "(function(){";
            echo "var table=document.getElementById(" . json_encode($id) . ");";
            echo "var list=" . json_encode($list) . ";";
            echo "var site=" . json_encode($this->site) . ";";
            echo "list.forEach(function(path){";
            echo "var row=table.querySelector(\"tr[data-name='\"+path+\"']\");";
            echo "var show=function(isOk,value){";
            echo "row.querySelector(\"[data-status=checking]\").classList.add(\"hidden\");";
            echo "row.querySelector(\"[data-result=\"+(isOk?\"ok\":\"error\")+\"]\").classList.remove(\"hidden\");";
            echo "if(!isOk){row.querySelector(\"[data-note=error]\").classList.remove(\"hidden\");}";
            echo "row.querySelector(\"[data-value]\").textContent=value;";
            echo "};";
            // A redirect (opaqueredirect, status 0) is not the file
            echo "fetch(site+path+\"?_=\"+Date.now(),{cache:\"no-store\",credentials:\"omit\",redirect:\"manual\"}).then(function(r){";
            echo "show(r.status!=200,r.status?\"HTTP \"+r.status:\"redirect\");";
            echo "}).catch(function(){show(true,\"-\");});";
            echo "});";
            echo "})();";
        });
    }

    protected function renderSummary($isReady)
    {
        if ($isReady) { ?>
            <div role="alert" class="alert alert-success alert-soft" data-ready="1">
                <?php $this->renderComponent("icons", array("icon" => "circle-check", "class" => "text-xl")); ?>
                <span><?php echo htmlspecialchars($this->text("ready"), ENT_QUOTES); ?></span>
            </div>
        <?php } else { ?>
            <div role="alert" class="alert alert-error alert-soft" data-ready="0">
                <?php $this->renderComponent("icons", array("icon" => "circle-x", "class" => "text-xl")); ?>
                <span><?php echo htmlspecialchars($this->text("not-ready"), ENT_QUOTES); ?></span>
            </div>
        <?php }
    }

    protected function renderHeading($key)
    {
        echo "<h3 class=\"font-semibold text-sm mt-2\">" . htmlspecialchars($this->text($key), ENT_QUOTES) . "</h3>";
    }

    protected function renderCard($id, $titleKey, $fn)
    {
        ?>
        <div class="card bg-base-100 border border-base-300 w-full" id="<?php echo $id; ?>">
            <div class="card-body gap-2">
                <h2 class="card-title"><?php echo htmlspecialchars($this->text($titleKey), ENT_QUOTES); ?></h2>
                <?php $fn(); ?>
            </div>
        </div>
        <?php
    }

    protected function renderSetup()
    {
        $php = Check::checkPHP();
        $writable = Check::checkWritable();
        $required = Check::checkRequiredExtension();
        $driver = Check::checkDatabaseDriver();
        $optional = Check::checkOptionalExtension();
        // The main limits only
        $limit = array_values(array_filter(Check::checkLimit(), function ($row) {
            return in_array($row["name"], array("memory_limit", "upload_max_filesize", "post_max_size", "max_execution_time", "file_uploads", "display_errors"), true);
        }));
        $isReady = !Check::hasErrors(array_merge($php, $writable, $required, $driver));

        $this->renderCard($this->id . "_setup", "title", function () use ($php, $writable, $required, $driver, $optional, $limit, $isReady) {
            $this->renderSummary($isReady);
            $this->renderHeading("php");
            $this->renderTable($this->id . "_php", $php);
            $this->renderHeading("folders");
            $this->renderTable($this->id . "_writable", $writable);
            $this->renderHeading("required-extensions");
            $this->renderBadges($this->id . "_required", $required);
            $this->renderHeading("database-drivers");
            $this->renderBadges($this->id . "_driver", $driver);
            $this->renderHeading("optional-extensions");
            $this->renderBadges($this->id . "_optional", $optional);
            $this->renderHeading("limits");
            $this->renderTable($this->id . "_limit", $limit);
            $this->renderHeading("protection");
            $this->renderProtectionCheck();
        });
    }

    protected function renderAdmin()
    {
        $php = Check::checkPHP();
        $writable = Check::checkWritable();
        $required = Check::checkRequiredExtension();
        $driver = Check::checkDatabaseDriver();
        $optional = Check::checkOptionalExtension();
        $isReady = !Check::hasErrors(array_merge($php, $writable, $required, $driver));

        $this->renderSummary($isReady);

        $this->renderCard($this->id . "_card-php", "php-and-extensions", function () use ($php, $required, $driver, $optional) {
            $this->renderTable($this->id . "_php", $php);
            $this->renderHeading("required-extensions");
            $this->renderBadges($this->id . "_required", $required);
            $this->renderHeading("database-drivers");
            $this->renderBadges($this->id . "_driver", $driver);
            $this->renderHeading("optional-extensions");
            $this->renderBadges($this->id . "_optional", $optional);
        });

        $this->renderCard($this->id . "_card-limit", "limits", function () {
            $this->renderTable($this->id . "_limit", Check::checkLimit());
        });

        $this->renderCard($this->id . "_card-folder", "folders", function () use ($writable) {
            $this->renderTable($this->id . "_writable", $writable);
            $this->renderHeading("protection");
            $this->renderProtectionCheck();
        });

        $this->renderCard($this->id . "_card-server", "server", function () {
            $this->renderTable($this->id . "_server", Check::checkServer($this->web));
        });

        $database = Check::checkDatabase($this->web);
        if (count($database)) {
            $this->renderCard($this->id . "_card-database", "database", function () use ($database) {
                $this->renderTable($this->id . "_database", $database);
            });
        }

        $this->renderCard($this->id . "_card-log", "logs", function () {
            $files = Check::logFiles();
            if (count($files) == 0) {
                echo "<p class=\"text-sm text-base-content/60\">" . htmlspecialchars($this->text("no-logs"), ENT_QUOTES) . "</p>";
                return;
            }
            ?>
            <table class="table table-sm" id="<?php echo $this->id; ?>_log">
                <tbody>
                    <?php foreach ($files as $file) { ?>
                        <tr>
                            <td class="font-mono text-sm"><?php echo htmlspecialchars($file["name"], ENT_QUOTES); ?></td>
                            <td class="font-mono text-sm text-right"><?php echo htmlspecialchars($file["size"], ENT_QUOTES); ?></td>
                            <td class="font-mono text-sm text-right"><?php echo htmlspecialchars($file["time"], ENT_QUOTES); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php
            $fileName = "";
            $content = Check::lastRouterLog($fileName);
            if (strlen($content)) {
                $this->renderHeading("router-log");
                echo "<p class=\"text-xs text-base-content/60\">" . htmlspecialchars(str_replace("{value}", $fileName, $this->text("router-log.note")), ENT_QUOTES) . "</p>";
                echo "<pre class=\"bg-base-200 rounded p-2 text-xs overflow-auto max-h-96 whitespace-pre-wrap break-all\" id=\"" . $this->id . "_router-log\">" . htmlspecialchars($content, ENT_QUOTES) . "</pre>";
            }
        });
    }

    public function render($options = null)
    {
        // Again on every render: the setup changes the language in the same request
        $this->loadLanguageFromPath("./_site/andora/web/component", "system-check", true);
        echo "<div class=\"flex flex-col gap-4 w-full\" id=\"" . $this->id . "\">";
        if ($this->mode === "admin") {
            $this->renderAdmin();
        } else {
            $this->renderSetup();
        }
        echo "</div>";
    }
}
