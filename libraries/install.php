<?php
/*
* SiteSense
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@sitesense.org so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade SiteSense to newer
* versions in the future. If you wish to customize SiteSense for your
* needs please refer to http://www.sitesense.org for more information.
*
* @author     Full Ambit Media, LLC <pr@fullambit.com>
* @copyright  Copyright (c) 2011 Full Ambit Media, LLC (http://www.fullambit.com)
* @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*/
define("INSTALLER", true);
$settings=array(
    'setupPassword'=> 'startitup',
    'saveToDb' => array(
        'siteTitle' => 'SiteSense',
        'homepage' => 'default',
        'theme' => 'default',
        'language' => 'en',
        'characterEncoding' => 'utf-8',
        'compressionEnabled' => 0,
        'compressionLevel' => 9,
        'userSessionTimeOut' => 1800, /* in seconds */
        'useModRewrite' => true,
        'hideContentGuests' => 'no',
        'showPerPage' => 5,
        'rawFooterContent' => '&copy; SiteSense',
        'parsedFooterContent' => '&copy; SiteSense',
        'cdnSmall' => '',
        'cdnFlash' => '',
        'cdnLarge' => '',
        'useCDN' => '0',
        'cdnBaseDir' => '',
        'defaultBlog' => 'news',
        'useBBCode' => '1',
        'jsEditor' => 'ckeditor',
        'version' => 'Pre-Alpha',
        'verifyEmail' => 1,
        'requireActivation' => 0,
        'removeAttribution' => 0
    )
);
echo '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html
  xmlns="http://www.w3.org/1999/xhtml"
  lang="en"
  xml:lang="en"
><head>
<meta
  http-equiv="Content-Type"
  content="text/html; charset=utf-8"
/>
<meta
  http-equiv="Content-Language"
  content="en"
/>
<link
  type="text/css"
  rel="stylesheet"
  href="themes/default/installer.css"
  media="screen,projection,tv"
/>
<title>
  SiteSense Installer
</title>
</head><body>
<h1>SiteSense Installer/Upgrader</h1>
';
if (
    !isset($_POST['spw']) ||
    ($_POST['spw']!==$settings['setupPassword'])
) {
    echo (
    (isset($_POST['spw']) && ($_POST['spw']!=$settings['setupPassword'])) ? '
<p class="error">Incorrect Setup Password</p>' :
        ''
    ),'
<form action="" method="post">
  <fieldset>
    <label for="spw">Please Enter Your Setup Password to Continue<br /></label>
    <input type="password" id="spw" name="spw" width="24" /><br /><br />
    <label for="cbDrop">
      <input type="checkBox" class="checkBox" id="cbDrop" name="cbDrop" value="drop" />
      Drop all tables first?<br />
    </label>
    <p class="warning">*** WARNING *** Dropping all tables will erase ALL entries in the CMS!</p>
  </fieldset>
</form>';
} else {
    $drop = false;
    if( isset($_POST['cbDrop']) && $_POST['cbDrop']=='drop' )
        $drop = true;
    $data->installErrors=0;
    $data->loadModuleQueries('installer',true);
    $data->loadCommonQueryDefines(true);
    $structures=installer_tableStructures();
    echo '<p>Connect to Database Successful</p>';

    if($drop) {
        $data->dropTable('settings');
        $data->dropTable('banned');
        $data->dropTable('sessions');
        $data->dropTable('sidebars');
        $data->dropTable('main_menu');
        $data->dropTable('activations');
        $data->dropTable('url_remap');
        $data->dropTable('modules');
        $data->dropTable('module_sidebars');
        // Dynamic User Permissions
        $data->dropTable('user_groups');
        $data->dropTable('user_group_permissions');
        $data->dropTable('user_permissions');
    }
    // Create the settings table
    if ($data->createTable('settings',$structures['settings'],false)) {
        try {
            $statement=$data->prepare('addSetting','installer');
            echo '
				<div>';
            foreach ($settings['saveToDb'] as $key => $value) {
                $statement->execute(array(
                    ':name' => $key,
                    ':category' => 'cms',
                    ':value' => $value
                ));
                $result=$statement->fetchAll();
                echo '
					Created ',$key,' Entry<br />';
            }
            echo '
				</div><br />';
        } catch (PDOException $e) {
            $data->installErrors++;
            echo '
				<h2>Database Connection Error</h2>
				<pre>'.$e->getMessage().'</pre>';
        }
    }

    // Install modules
    $coreModules = array(
        'dynamicForms',
        'dynamicURLs',
        'default',
        'blogs',
        'pages',
        'login',
        'logout',
        'plugins',
        'register',
        'users',
        'mainMenu',
        'sidebars',
        'settings',
        'modules',
        'plugins'
    );

    $uninstalledModuleFiles = glob('modules/*/*.install.php');
    $moduleSettings=array();
    foreach($uninstalledModuleFiles as $moduleInstallFile) {
        // Include the install file for this module
        if(!file_exists($moduleInstallFile)) {
            $data->output['rejectError']='Module installation file does not exist';
            $data->output['rejectText']='The module installation could not be found.';
        } else {
            common_include($moduleInstallFile);
            // Extract the name of the module from the filename
            $dirEnd=strrpos($moduleInstallFile,'/')+1;
            $nameEnd=strpos($moduleInstallFile,'.');
            $moduleName=substr($moduleInstallFile,$dirEnd,$nameEnd-$dirEnd);
            if(in_array($moduleName,$coreModules)) {
                // Run the module installation procedure
                $targetFunction=$moduleName.'_install';
                if(!function_exists($targetFunction)) {
                    $data->output['rejectError']='Improper installation file';
                    $data->output['rejectText']='The module install function could not be found within the module installation file.';
                } elseif($moduleName=='users') {
                    $newPassword=$targetFunction($data,$drop);
                } else {
                    $targetFunction($data,$drop);
                }
                $targetFunction=$moduleName.'_settings';
                if(function_exists($targetFunction)) {
                    $moduleSettings[$moduleName]=$targetFunction();
                } else {
                    $data->output['rejectError']='Improper installation file';
                    $data->output['rejectText']='The module install function could not be found within the module installation file.';
                }
            } else if ($drop) {
                // Run the module uninstall procedure
                $targetFunction=$moduleName.'_uninstall';
                if(!function_exists($targetFunction)) {
                    $data->output['rejectError']='Improper installation file';
                    $data->output['rejectText']='The module uninstall function could not be found within the module installation file.';
                } else $targetFunction($data);
            }
        }
    }

    $moduleFiles=glob('modules/*/*.module.php');
    // Build an array of the names of the modules in the filesystem
    $fileModules=array_map(
        function($path) {
            $dirEnd=strrpos($path,'/')+1;
            $nameEnd=strpos($path,'.');
            return substr($path,$dirEnd,$nameEnd-$dirEnd);
        },
        $moduleFiles
    );
    // Insert new modules into the database
    $insert=$data->prepare('newModule');
    foreach($fileModules as $fileModule) {
        $shortName=$fileModule;
        if(array_key_exists($fileModule,$moduleSettings)) {
            if(array_key_exists('shortName',$moduleSettings[$fileModule])) {
                $shortName=$moduleSettings[$fileModule]['shortName'];
            }
        }
        $enabled=in_array($fileModule,$coreModules) ? 1 : 0;
        $insert->execute(
            array(
                ':name' => $fileModule,
                ':shortName' => $shortName,
                ':enabled' => $enabled
            )
        );
    }

    // Install plugins
    if($drop) {
        $data->dropTable('plugins');
        $data->dropTable('plugins_modules');
    }
    $data->createTable('plugins',$structures['plugins'],false);
    $data->createTable('plugins_modules',$structures['plugins_modules'],false);
    // Get Plugins That Have Yet To Be Installed
    $dirs=scandir('plugins');
    foreach($dirs as $dir) {
        if(strpos($dir,'.')) continue;
        // Include the install file for this plugin
        if(file_exists('plugins/'.$dir.'/install.php'))
            common_include('plugins/'.$dir.'/install.php');
        // Get the plugin's settings
        $targetFunction=$dir.'_settings';
        if(function_exists($targetFunction)) {
            $settings=$targetFunction();
            // Run the plugin installation procedure
            $targetFunction=$dir.'_install';
            if(function_exists($targetFunction)) {
                $targetFunction($data,$data);
                // Add this plugin to the database
                $statement=$data->prepare('addPlugin','installer');
                $statement->execute(array(
                    ':pluginName'		 => $dir,
                    ':isCDN'				 => isset($settings['isCDN']) ? $settings['isCDN'] : '0',
                    ':isEditor'			 => isset($settings['isEditor']) ? $settings['isEditor'] : '0'
                ));
            }
        }
    }
    // Set up default permission groups
    $defaultPermissionGroups=array(
      // Admin has universal access by defaul, this list is commented just for reference on full list
		'Administrators' => array(),
		'Writer' => array(
            'core_access',

            'dashboard_access',

            'mainMenu_access',
            'mainMenu_add',
            'mainMenu_delete',
            'mainMenu_disable',
            'mainMenu_edit',
            'mainMenu_enable',
            'mainMenu_list',

            'sidebars_access',
            'sidebars_add',
            'sidebars_delete',
            'sidebars_edit',
            'sidebars_list',

            'dynamicURLs_access',
            'dynamicURLs_add',
            'dynamicURLs_delete',
            'dynamicURLs_edit',
            'dynamicURLs_list'
		),
		'Moderator' => array(
            'core_access',

            'dashboard_access'
		),
		'Blogger' => array(
            'core_access',

            'dashboard_access'
		),
		'User' => array(
            'core_access',

            'dashboard_access'
		)
    );
    foreach($defaultPermissionGroups as $groupName => $permissions) {
        $statement=$data->prepare('addUserToPermissionGroupNoExpires');
        if($groupName=='Administrators') {
            $statement->execute(
                array(
                    ':userID'    => '1',
                    ':groupName' => $groupName
                )
            );
        }
        foreach($permissions as $permissionName) {
            $statement=$data->prepare('addPermissionByGroupName');
            $statement->execute(
                array(
                    ':groupName' => $groupName,
                    ':permissionName' => $permissionName
                )
            );
        }
    }
    if ($data->installErrors==0) {
        echo '
      <h2 id="done">Complete</h2>
      <p class="success">
        Installation/Verification Completed Successfully
      </p><p>
        It is recommended to log into the Admin panel and go to the "mainMenu" function to populate the menu functions. Until you do so, there will be no menu. Any sidebars you have installed will also not show until you enable them in the Admin sidebar control.
      </p>';
        if (isset($newPassword)) {
            echo '
      <p>
        A new administrator login was created. You must use the following information to log into the system:
      </p>
      <dl>
        <dt>Username:</dt><dd>admin</dd>
        <dt>Password:</dt><dd>',$newPassword,'</dd>
      </dl>
      <p>
        Changing the password is recommended. <a href="admin/users/edit/1/" class="error">Click here</a> to login to the admin panel.
      </p>';
        }
    } else {
        echo '
      <h2 id="done">Errors Present</h2>
      <p>
        We were unable to build the databases properly. Please review the above errros before attempting to use this installation.
      </p>';
    }
}
echo '
</body></html>';
?>