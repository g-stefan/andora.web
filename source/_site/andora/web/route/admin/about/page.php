<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

namespace Andora\Web\Route\Admin\About;

defined("ANDORA") or die("Forbidden");

use \Andora\Web\Model\Dashboard;
use \Andora\Web\Component\LogoAndora;

// About: the Andora logo, the version (version.json, set by the build in
// andora.php) and the license.
class Page extends \XYO\Web\Page
{
    // The third party components shipped with Andora: name, license (SPDX), URL
    protected static $thirdParty = array(
        array("XYO.Web", "Apache-2.0", "https://github.com/g-stefan/xyo.web"),
        array("PHPMailer", "LGPL-2.1-only", "https://github.com/PHPMailer/PHPMailer"),
        array("Tailwind CSS", "MIT", "https://tailwindcss.com"),
        array("daisyUI", "MIT", "https://daisyui.com"),
        array("Lucide", "ISC", "https://lucide.dev"),
        array("Inter", "OFL-1.1", "https://rsms.me/inter"),
        array("OverlayScrollbars", "MIT", "https://kingsora.github.io/OverlayScrollbars")
    );

    public function init($options = null)
    {
        $this->setTitle("Admin - About");
        Dashboard::setApplicationTitle("About");
        LogoAndora::register($this, "logo-andora");
    }

    public function render($options = null)
    { ?>
        <div class="flex flex-col items-center gap-6 w-full max-w-[832px] mx-auto" id="about">
            <div class="w-full max-w-[416px] aspect-[832/464]">
                <?php $this->renderComponent("logo-andora", array("class" => "logo-andora-contain")); ?>
            </div>

            <div class="card bg-base-100 border border-base-300 w-full" id="about-version">
                <div class="card-body">
                    <h2 class="card-title"><?php $this->language->render("version.title"); ?></h2>
                    <table class="table table-sm">
                        <tbody>
                            <tr>
                                <th class="w-1/3"><?php $this->language->render("version"); ?></th>
                                <td class="font-mono"><?php echo htmlspecialchars(ANDORA_VERSION_STR, ENT_QUOTES); ?></td>
                            </tr>
                            <tr>
                                <th><?php $this->language->render("build"); ?></th>
                                <td class="font-mono"><?php echo htmlspecialchars(ANDORA_VERSION_STR_BUILD, ENT_QUOTES); ?></td>
                            </tr>
                            <tr>
                                <th><?php $this->language->render("date"); ?></th>
                                <td class="font-mono"><?php echo htmlspecialchars(ANDORA_VERSION_STR_DATETIME, ENT_QUOTES); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300 w-full" id="about-license">
                <div class="card-body gap-3">
                    <h2 class="card-title"><?php $this->language->render("license.title"); ?></h2>
                    <p>Copyright &copy; 2026 Grigore Stefan &lt;g_stefan@yahoo.com&gt;</p>
                    <p><?php $this->language->render("license.notice"); ?></p>
                    <p>
                        <a class="link link-primary" href="https://www.apache.org/licenses/LICENSE-2.0" target="_blank" rel="noopener noreferrer">https://www.apache.org/licenses/LICENSE-2.0</a>
                    </p>
                    <p class="text-sm text-base-content/70"><?php $this->language->render("license.disclaimer"); ?></p>

                    <h3 class="font-semibold mt-2"><?php $this->language->render("third-party.title"); ?></h3>
                    <table class="table table-sm" id="about-third-party">
                        <thead>
                            <tr>
                                <th><?php $this->language->render("third-party.component"); ?></th>
                                <th><?php $this->language->render("third-party.license"); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (self::$thirdParty as $entry) { ?>
                                <tr>
                                    <td><a class="link" href="<?php echo htmlspecialchars($entry[2], ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($entry[0], ENT_QUOTES); ?></a></td>
                                    <td class="font-mono"><?php echo htmlspecialchars($entry[1], ENT_QUOTES); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php }

}

return Page::class;
