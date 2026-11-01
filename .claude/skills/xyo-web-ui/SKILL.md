---
name: xyo-web-ui
description: Build XYO.Web UI — Pages, Layouts, Components (server-rendered and AJAX), and Forms in this PHP framework. Use when creating or editing a page.php/layout.php, a class extending \XYO\Web\Page, \XYO\Web\Layout, \XYO\Web\Component or \XYO\Web\ComponentForm, wiring components with register()/renderComponent(), adding AJAX-refreshing UI, or registering CSS/JS assets through the View.
---

# XYO.Web UI (Pages, Layouts, Components, Forms)

XYO.Web is a PHP framework where the UI is a tree of **Modules**. The three
concrete module kinds you author are **Page**, **Layout**, and **Component**
(plus **ComponentForm**). Rendering is plain PHP-in-HTML (echo / `?>...<?php`),
and each module can register child components and page assets through a shared
**View**.

## The module lifecycle

`Page`, `Layout`, and `Component` all extend `\XYO\Web\Module`. The router runs
a fixed lifecycle:

1. `init($options)` — build state: register child components, fetch data sources, read the request/session. **Register components here.**
2. `initComponents()` — the framework recursively `init()`s + `process()`es all registered children.
3. `process($options)` — post-init work after children are ready.
4. `render($options)` — emit HTML.

Register children in `init()`, render them in `render()`. Do not render in
`init()`.

### Shared members available in every module

- `$this->view` — page-wide View (title, CSS/JS registration, CSRF token). See "Assets" below.
- `$this->request` — the HTTP request (`get`, `getQuery`, `isGET/isPOST/isAJAX/isJSON`).
- `$this->session` — server session (`get($key,$default)`, `set($key,$value)`).
- `$this->config`, `$this->language`, `$this->info`, `$this->site`.
- `$this->getDataSource($class[, $connName])` — obtain a Table/Query (see the **xyo-web-datasource** skill).
- Request predicates: `$this->isGET()`, `$this->isPOST()`, `$this->isAJAX()`, `$this->isJSON()`, `$this->isComponentAJAX()`.

## 1. Page

A `page.php` file **returns the class name** at the end. The router maps a URL
path to the `page.php` in that directory. `Page` adds `setTitle()`.

```php
<?php

namespace Example;

defined("XYO_WEB") or die("Forbidden");

use \DataSource\TableCounter;

class Home extends \XYO\Web\Page
{
    public function init($options = null)
    {
        $this->setTitle("Home");

        // Register components by id (used later with renderComponent()).
        ComponentServer::register($this, "server");
        ComponentAjaxDB::register($this, "counter");

        // Ensure schema exists (idempotent).
        $this->getDataSource(TableCounter::class)->createStorage();
    }

    public function render($options = null)
    { ?>
        <div class="min-h-screen bg-slate-100 p-6">
            <?php $this->renderComponent("server"); ?>
            <?php $this->renderComponent("counter"); ?>
        </div>
    <?php }
}

return Home::class;   // REQUIRED: the file returns the class name
```

**Routing:** the URL directory resolves to `page.php` in that directory; a
missing page falls back to the framework default. Files the router looks for in
a route directory: `page.php` (page), `api.php` (API), `slug.php`,
`service.php`, plus a `layout.php` (searched up the directory tree) and an
optional `authorization.php`.

## 2. Layout

`layout.php` also **returns its class name**. It wraps the page: it emits the
`<html>/<head>/<body>` shell and calls back into the framework to flush the
registered head assets, the page body, and the deferred scripts. The nearest
`layout.php` up the tree is used; otherwise the framework default.

```php
<?php
namespace Example;
defined("XYO_WEB") or die("Forbidden");

class SiteLayout extends \XYO\Web\Layout
{
    public function renderLayout($page = null)
    { ?>
<!DOCTYPE html>
<html <?php $this->renderLanguage(); ?> <?php $this->renderHTMLClasses(); ?>>
<head><?php $this->renderHead(); ?></head>
<body <?php $this->renderBodyClasses(); ?>>
    <?php $this->renderPage($page); ?>
    <?php $this->renderScripts(); ?>
</body>
</html>
    <?php }
}

return SiteLayout::class;
```

Layout helpers: `renderHead()` (meta/links/title/CSS), `renderScripts()`
(JS links + deferred JS), `renderPage($page)`, `renderHTMLClasses()`,
`renderBodyClasses()`, `renderLanguage()`.

## 3. Component

Components are reusable UI units. Register them on a parent, render them by id.
A component may render the **same every request** (override `render()`) or
support **AJAX self-refresh** (override `renderAJAX()` + `renderContainer()`).

### Registration & rendering

```php
// In the parent's init():
MyComponent::register($this, "my-id");          // register(parent, id, options)
// or register + init immediately:
MyComponent::registerAndInit($this, "my-id");

// In the parent's render():
$this->renderComponent("my-id");                 // render a child
$this->renderComponent("my-id", $options);       // with options
$html = $this->strRenderComponent("my-id");      // capture as string
```

`register()` is a static helper (`Component::register($parent, $id, $options)`).
Ids are dotted paths for nesting (`"parent.child"`). Omitting an id
auto-generates one.

### Plain (always server-rendered) component

```php
<?php
namespace Example;
defined("XYO_WEB") or die("Forbidden");

class ComponentServer extends \XYO\Web\Component
{
    protected $count;

    public function init($options = null)
    {
        if ($this->isPost()) {
            $this->session->set("count", $this->session->get("count", 0) + 1);
        }
        $this->count = $this->session->get("count", 0);
    }

    public function render($options = null)
    { ?>
        <div class="card">
            <div class="text-2xl font-bold">Counter: <?php echo $this->count; ?></div>
            <form method="POST">
                <button type="submit">Submit</button>
                <?php $this->view->renderTokenAsFormInput(); ?>  <!-- CSRF token -->
            </form>
        </div>
    <?php }
}
```

### AJAX-refreshing component

The base `Component::render()` automatically dispatches: when the request
targets this component over AJAX it calls `renderAJAX()`; otherwise
`renderContainer()`. Pattern:

- `renderContainer()` — emit a wrapper `<div id="<?php echo $this->id; ?>">`, render the initial `renderAJAX()` inside it, then schedule the refresh JS.
- `renderAJAX()` — emit just the inner markup that gets swapped in on each refresh.
- Trigger a refresh with `renderJSRequestPost()` / `renderJSRequestGet()` (targets this component by its request id).

```php
class ComponentAjaxDB extends \XYO\Web\Component
{
    protected $table = null;

    public function init($options = null)
    {
        $this->table = $this->getDataSource(\DataSource\TableCounter::class);

        // On an AJAX POST to this component, mutate then reload.
        if ($this->isAJAX() && $this->isPost()) {
            $this->table->clear();
            for ($this->table->load(0, 10); $this->table->loadIsValid(); $this->table->loadNext()) {
                $this->table->count += 1;
                $this->table->save();
            }
        }
        $this->table->clear();
        $this->table->id = 1;
        $this->table->load(0, 1);
    }

    public function renderAJAX($options = null)
    { ?>
        <div class="text-2xl font-bold">Counter: <?php echo $this->table->count; ?></div>
    <?php }

    public function renderContainer($options = null)
    {
        echo "<div id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";

        // Deferred script: poll this component every 5s and swap in the new HTML.
        $this->view->jsSource(function () { ?>
            <script>
                setInterval(function () {
                    <?php $this->renderJSRequestPost(); ?>
                }, 5000);
            </script>
        <?php }, "load");
    }
}
```

**AJAX request emitters** (echo a JS call that fetches this component and
replaces its `#id` element):

- `renderJSRequestGet($payload, $payloadJs, $selector)` / `renderJSRequestPost(...)` — target this component.
- `renderJSRequestPostForm($formId, ...)` — submit a form's fields.
- `...ToElement($id, ...)` — write the response into a different element id.
- `renderJSRequestBatchGet/BatchPost($idList, ...)` — refresh several components in one request.

POST variants automatically include the CSRF token (`$this->view->token`).
`$payload` is an assoc array of literal values; `$payloadJs` an assoc array of
raw JS expressions.

## 4. ComponentForm

`\XYO\Web\ComponentForm` extends `Component` with form helpers: a stable form
id, per-element ids, value getters, and per-field alert/error state. Use
`renderFormAJAX()` to emit a `<form>` that submits over AJAX and swaps itself in.

```php
class ComponentContact extends \XYO\Web\ComponentForm
{
    public function renderAJAX($options = null)
    {
        $this->renderFormAJAX(function () { ?>
            <label>First name</label>
            <input type="text" name="first-name" />
            <button type="submit">Save</button>
            <?php
            echo "You entered: " . $this->getElementValueString("first-name");
        });
    }

    public function renderContainer($options = null)
    {
        echo "<div id=\"" . $this->id . "\">";
        $this->renderAJAX();
        echo "</div>";
    }
}
```

Form helpers:
- `renderForm($fn, $opts)` — plain POST form (auto-includes hidden component id + CSRF token via `renderComponentFormRequiredFields()`).
- `renderFormAJAX($fn, $opts, $payload, $payloadJs)` — AJAX-submitting form (wires a `submit` handler that posts the form and replaces the container).
- `getElementValue($name, $default)`, `getElementValueString($name, $default, $maxLen)`, `getElementValueNumber($name, $default)` — read submitted values (from `$this->request`).
- `getElementId($name)`, `getFormId()` — stable, collision-free ids.
- Validation state: `setElementError/Alert($name,$msg)`, `getElementError/Alert`, `elementHasError/Alert`, `hasError()`, `hasAlert()`, `clearError()`, `clearAlert()`.

## 5. Assets via the View (`$this->view`)

Register page-level assets from any module's `init()`/`render()`; the Layout
flushes them in the right place. Registration is deduplicated per page.

- CSS: `$this->view->cssLinks`, `$this->view->cssSources`, or inline via `$this->view->cssSource($fn)` / `renderCSS($fn)`.
- JS: `$this->view->jsLinks`, or deferred inline via `$this->view->jsSource($fn, "load")` (runs on window `load`) / immediate `renderJS($fn)`.
- Title: `$this->setTitle(...)` (Page) → `$this->view->title`.
- CSRF: `$this->view->token`, `$this->view->renderTokenAsFormInput()`.
- Reusable asset bundles register themselves as components, e.g. `LucideIcons::register($this, "icons")`, then `$this->renderComponent("icons", "database")` renders a named icon.

`jsSource($fn, "load")` and `cssSource($fn)` extract the inner `<script>` /
`<style>` body from the callback's output and queue it; `"load"` mode defers
execution until the page `load` event (correct for code that touches the DOM).

## Checklist for a new page

1. Create `page.php` in the route directory, `namespace ...;`, `defined("XYO_WEB") or die("Forbidden");`.
2. `class X extends \XYO\Web\Page`; `return X::class;` at the end of the file.
3. In `init()`: `setTitle()`, `register()` each child component by id, prepare data sources (`getDataSource(...)->createStorage()`).
4. In `render()`: emit HTML and `renderComponent("id")` for each child.
5. Ensure a `layout.php` exists up the tree (or rely on the framework default).
6. For dynamic UI, make the component AJAX-capable (`renderContainer()` + `renderAJAX()` + a `renderJSRequestPost()` trigger).

## Common mistakes

- Forgetting `return X::class;` at the end of `page.php`/`layout.php` — the router requires the returned class name.
- Registering components in `render()` instead of `init()` — register in `init()`, render in `render()`.
- Omitting the CSRF token on POST/AJAX-POST forms — use `renderTokenAsFormInput()` or `renderComponentFormRequiredFields()` (POST AJAX emitters add it automatically).
- Missing the wrapper `<div id="<?php echo $this->id; ?>">` around `renderAJAX()` output — the AJAX response is swapped into that element by id.
- Echoing untrusted values without escaping — use `htmlspecialchars(..., ENT_QUOTES)` for attribute/text interpolation.

See the sibling **xyo-web-datasource** skill for defining the Tables/Queries these pages read and write.
