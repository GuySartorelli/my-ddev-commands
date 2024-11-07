<?php

namespace DevTools\Extension;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Core\Extension;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Sets the SiteConfig default title to match the application name.
 * The application name was set to the DDEV project name in appname.yml
 *
 * @extends Extension<SiteConfig>
 */
class SiteConfigExtension extends Extension
{
    protected function onAfterPopulateDefaults(): void
    {
        $this->getOwner()->Title = LeftAndMain::config()->get('application_name');
    }
}
