<?php

/**
 * Copyright (c) 2001-2012 Zikula Foundation
 *
 * This work is contributed to the Zikula Foundation under one or more
 * Contributor Agreements and licensed to You under the following license:
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.html GNU/LGPLv3 (or at your option any later version).
 * @package Legal
 *
 * Please see the NOTICE file distributed with this source code for further
 * information regarding copyright and licensing.
 */

namespace Zikula\LegalModule;

use Zikula\LegalModule\Constant as LegalConstant;
use EventUtil;

/**
 * Installs, upgrades, and uninstalls the Legal module.
 */
class LegalModuleInstaller extends \Zikula_AbstractInstaller
{
    /**
     * Install the module.
     *
     * @return bool true if successful, false otherwise
     */
    public function install()
    {
        // Set default values for the module variables
        $this->setVar(LegalConstant::MODVAR_LEGALNOTICE_ACTIVE, true);
        $this->setVar(LegalConstant::MODVAR_TERMS_ACTIVE, true);
        $this->setVar(LegalConstant::MODVAR_PRIVACY_ACTIVE, true);
        $this->setVar(LegalConstant::MODVAR_ACCESSIBILITY_ACTIVE, true);
        $this->setVar(LegalConstant::MODVAR_CANCELLATIONRIGHTPOLICY_ACTIVE, false);
        $this->setVar(LegalConstant::MODVAR_TRADECONDITIONS_ACTIVE, false);
        $this->setVar(LegalConstant::MODVAR_LEGALNOTICE_URL, '');
        $this->setVar(LegalConstant::MODVAR_TERMS_URL, '');
        $this->setVar(LegalConstant::MODVAR_PRIVACY_URL, '');
        $this->setVar(LegalConstant::MODVAR_ACCESSIBILITY_URL, '');
        $this->setVar(LegalConstant::MODVAR_CANCELLATIONRIGHTPOLICY_URL, '');
        $this->setVar(LegalConstant::MODVAR_TRADECONDITIONS_URL, '');
        $this->setVar(LegalConstant::MODVAR_MINIMUM_AGE, 13);
        // Set up the persistent event handler, hook bundles, and any other event-related features.
        EventUtil::registerPersistentModuleHandler($this->name, 'user.login.veto', array('Zikula\LegalModule\Listener\UsersLoginVetoListener', 'acceptPoliciesListener'));
        EventUtil::registerPersistentEventHandlerClass($this->name, 'Zikula\LegalModule\Listener\UsersUiHandlerListener');
        // Initialization successful
        return true;
    }
    
    /**
     * Upgrade the module from a prior version.
     *
     * This function must consider all the released versions of the module!
     * If the upgrade fails at some point, it returns the last upgraded version.
     *
     * @param string $oldVersion The version number string from which the upgrade starting.
     *
     * @return boolean|string True if the module is successfully upgraded to the current version; last valid version string or false if the upgrade fails.
     */
    public function upgrade($oldVersion)
    {
        // Upgrade dependent on old version number
        switch ($oldVersion) {
            case '1.1':
                // Upgrade 1.1 -> 1.2
                $this->setVar('termsofuse', true);
                $this->setVar('privacypolicy', true);
                $this->setVar('accessibilitystatement', true);
            case '1.2':
            // Upgrade 1.2 -> 1.3
            // Nothing to do.
            case '1.3':
                // Upgrade 1.3 -> 2.0.0
                // Convert the module variables to the new names
                $this->setVar(LegalConstant::MODVAR_TERMS_ACTIVE, $this->getVar('termsofuse', true));
                $this->delVar('termsofuse');
                $this->setVar(LegalConstant::MODVAR_PRIVACY_ACTIVE, $this->getVar('privacypolicy', true));
                $this->delVar('privacypolicy');
                $this->setVar(LegalConstant::MODVAR_ACCESSIBILITY_ACTIVE, $this->getVar('accessibilitystatement', true));
                $this->delVar('accessibilitystatement');
                // Set the new module variable -- but if Users set it for us during its upgrade, then don't overwrite it
                $this->setVar(LegalConstant::MODVAR_MINIMUM_AGE, $this->getVar(LegalConstant::MODVAR_MINIMUM_AGE, 0));
                // Set up the new persistent event handler, and any other event-related features.
                EventUtil::registerPersistentModuleHandler($this->name, 'user.login.veto', array('Legal_Listener_UsersLoginVeto', 'acceptPoliciesListener'));
                EventUtil::registerPersistentEventHandlerClass($this->name, 'Legal_Listener_UsersUiHandler');
            case '2.0.0':
                // Upgrade 2.0.0 -> 2.0.1
                // add vars for new document types
                $this->setVar(LegalConstant::MODVAR_LEGALNOTICE_ACTIVE, false);
                $this->setVar(LegalConstant::MODVAR_CANCELLATIONRIGHTPOLICY_ACTIVE, false);
                $this->setVar(LegalConstant::MODVAR_TRADECONDITIONS_ACTIVE, false);
                // add vars for optional custom urls
                $this->setVar(LegalConstant::MODVAR_LEGALNOTICE_URL, '');
                $this->setVar(LegalConstant::MODVAR_TERMS_URL, '');
                $this->setVar(LegalConstant::MODVAR_PRIVACY_URL, '');
                $this->setVar(LegalConstant::MODVAR_ACCESSIBILITY_URL, '');
                $this->setVar(LegalConstant::MODVAR_CANCELLATIONRIGHTPOLICY_URL, '');
                $this->setVar(LegalConstant::MODVAR_TRADECONDITIONS_URL, '');
            case '2.0.1':
                // Upgrade 2.0.1 -> 2.0.3
                EventUtil::unregisterPersistentModuleHandlers('Legal'); // using old name on purpose here
                // register handlers under new modname and with new classes
                EventUtil::registerPersistentModuleHandler($this->name, 'user.login.veto', array('Zikula\LegalModule\Listener\UsersLoginVetoListener', 'acceptPoliciesListener'));
                EventUtil::registerPersistentEventHandlerClass($this->name, 'Zikula\LegalModule\Listener\UsersUiHandlerListener');
                // @todo write upgrade for permissions?
            case '2.0.3': //current version
                // Upgrade 2.0.3 -> ?.?.?
                // The following break should be the only one in the switch, and should appear immediately prior to the default case.
                break;
            default:
        }
        // Update successful
        return true;
    }
    
    /**
     * Delete the Legal module.
     *
     * @return bool True if successful; otherwise false.
     */
    public function uninstall()
    {
        $this->delVars();
        // Deletion successful
        return true;
    }

}