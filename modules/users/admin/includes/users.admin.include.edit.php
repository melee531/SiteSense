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
common_include('libraries/forms.php');
function getPermissions($data,$db) {
    $targetFunction='loadPermissions';
    // Get core permissions
    if (function_exists($targetFunction)) {
        $targetFunction($data);
    }
}
function checkUserName($name,$db) {
	$statement=$db->prepare('checkUserName','admin_users');
	$statement->execute(array(
		':name' => $name
	));
	return $statement->fetchColumn();
}
function admin_usersBuild($data,$db) {
	//permission check for users edit
    if(!checkPermission('edit','users',$data)) {
		$data->output['abort'] = true;
		$data->output['abortMessage'] = '<h2>Insufficient User Permissions</h2>You do not have the permissions to access this area.';	
		return;
	}
    if($data->user['id']!==$data->action[3] && !checkPermission('accessOthers','users',$data)) {
        $data->output['abort'] = true;
        $data->output['abortMessage'] = '<h2>Insufficient User Permissions</h2>You do not have the permissions to access this area.';
        return;
    }
    // Load all groups
    $db->query('purgeExpiredGroups');
    $statement=$db->query('getAllGroups','admin_users');
    $data->output['groupList']=$statement->fetchAll();
    $data->output['groupList'][]['groupName']='Administrators';
    sort($data->output['groupList']);

    // Load all groups by userID
    $statement=$db->prepare('getGroupsByUserID');
    $statement->execute(array(
        ':userID' =>  $data->action[3]
    ));
    $data->output['userGroupList']=$statement->fetchAll();

    // Load core permissions
    getPermissions($data,$db);
    // Get User Permissions
    $statement=$db->prepare('getUserPermissionsByUserID');
    $statement->execute(array(
        ':userID' =>  $data->action[3]
    ));
    $permissions=$statement->fetchAll(PDO::FETCH_ASSOC);
    /*
    $statement=$db->query('getAllGroups','admin_users');
    $groupList=$statement->fetchAll(PDO::FETCH_ASSOC);

    foreach($groupList as $key => $value) {
        if($groupList[$key]['groupName']==$data->action[5]) {
            $existing=true;
        }
    }
    */
    if(isset($permissions)) {
        foreach($permissions as $key => $permission) {
            $permissionName=$permission['permissionName'];
            $allow=$permission['allow'];
            $separator=strpos($permissionName,'_');
            $prefix=substr($permissionName,0,$separator);
            $suffix=substr($permissionName,$separator+1);
            $data->output['userForm']['permissions'][$prefix][]=$suffix;
            $data->output['userForm']['permissions'][$prefix][$suffix]['allow']=$allow;
        }

    }
    // ---
    $data->output['userForm']=$form=new formHandler('addEdit',$data,true);

    $statement=$db->prepare('getById','admin_users');
    $statement->execute(array(
        ':id' => $data->action[3]
    ));


	
	if (($item=$statement->fetch()) !== FALSE) {
		
		$data->output['userForm']->caption = 'Editing User '.$item['name'];

		foreach ($data->output['userForm']->fields as $key => $value) {

           switch ($key) {
                case 'lastAccess':
                    $data->output['userForm']->fields[$key]['value']=(
                    ($item[$key]==0) ?
                        'never' :
                        gmdate(
                            'd F Y - G:i:s',
                            strtotime($item[$key])
                        )
                    );
                    $data->output['userForm']->fields[$key.'_hidden']['value']=$data->output['userForm']->fields[$key]['value'];
                break;
				case 'registeredDate':
                    $data->output['userForm']->fields[$key]['value']=(
						($item[$key]==0) ?
						'never' :
						gmdate(
							'd F Y - G:i:s',
							strtotime($item[$key])
						)
					);
                    $data->output['userForm']->fields[$key.'_hidden']['value']=$data->output['userForm']->fields[$key]['value'];
				break;
                case 'id':
                case 'registeredIP':
                    $data->output['userForm']->fields[$key.'_hidden']['value']=$item[$key];
                case 'firstName':
                case 'lastName':
                case 'name':
                case 'contactEMail':
                case 'publicEMail':
					$data->output['userForm']->fields[$key]['value']=$item[$key];
                break;
		    }
		}
	} else {
		$data->output['abort'] = true;
		$data->output['abortMessage'] = 'The user you specified could not be found';
	}
	
	if ((!empty($_POST['fromForm'])) && ($_POST['fromForm']==$data->output['userForm']->fromForm))
	{
		/*
			we came from the form, so repopulate it and set up our
			sendArray at the same time.
		*/
		$data->output['userForm']->populateFromPostData();
		$existing = false;
		// Check If UserName Already Exists (ONLY IF DIFFERENT) //
		if($form->sendArray[':name'] !== $item['name'])
		{
			$existing = checkUserName($form->sendArray[':name'],$db);
		}
		
		if($existing)
		{
			$data->output['secondSidebar']='
				  <h2>Error in Data</h2>
				  <p>
					  There were one or more errors. Please correct the fields with the red X next to them and try again.
				  </p><p>
					  <strong>That username is already taken!</strong>
				  </p>';
				  
			$data->output['userForm']->fields['name']['error'] = true;
				  
			return;
		}
		
		
		if (($data->output['userForm']->validateFromPost())) {
			// Update User Permissions in DB
            // Purge all of a user's permissions
            $statement=$db->prepare('removeAllUserPermissionsByUserID');
            $statement->execute(array(
                ':userID' => $data->action[3]
            ));
            // Insert Permissions
            foreach($data->permissions as $category => $permissions) {
                foreach($permissions as $permissionName => $permissionDescription) {
                    if(isset($data->output['userForm']->sendArray[':'.$category.'_'.$permissionName])) {
                        if($data->output['userForm']->sendArray[':'.$category.'_'.$permissionName]!=='Inherited') {
                            $allow=0;
                            if($data->output['userForm']->sendArray[':'.$category.'_'.$permissionName]=='Allow') {
                                $allow=1;
                            }
                            // Add it to the database
                            $statement=$db->prepare('addPermissionsByUserId');
                            $statement->execute(array(
                                ':id' => $data->action[3],
                                ':permission' => $category.'_'.$permissionName,
                                ':allow' => $allow
                            ));
                        }
                    }
                    unset($data->output['userForm']->sendArray[':'.$category.'_'.$permissionName]);
                }
            }
            // Insert Groups
            // Remove expired groups
            $db->query('purgeExpiredGroups');
            foreach($data->output['groupList'] as $key => $value) {
                $member=0;
                $expires='Never';
                foreach($data->output['userGroupList'] as $subKey => $subValue) {
                    if($subValue['groupName']==$value['groupName']) {
                        // User must be already a member of the group
                        $member=1;
                    }
                }
                switch($data->output['userForm']->sendArray[':'.$value['groupName'].'_update']) {
                    case 'No change':
                        $expires='No change';
                        break;
                    case 'Never':
                        $expires=0;
                        break;
                    case '15 minutes':
                        $expires=900;
                        break;
                    case '1 hour':
                        $expires=3600;
                        break;
                    case '2 hours':
                        $expires=7200;
                        break;
                    case '1 day':
                        $expires=86400;
                        break;
                    case '2 days':
                        $expires=172800;
                        break;
                    case '1 week':
                        $expires=604800;
                        break;
                }
                if($member) {
                    // User already is a member of the group
                    if($data->output['userForm']->sendArray[':'.$value['groupName']]) {
                        // User is still a member
                        // Check to see if expiration has changed
                        if($expires!=='No change') {
                            if($expires==0) {
                                $statement=$db->prepare('updateExpirationInPermissionGroupNoExpires');
                                $statement->execute(array(
                                    ':userID' => $data->action[3],
                                    ':groupName' => $value['groupName']
                                ));
                            } else {
                                // Update expiration
                                $statement=$db->prepare('updateExpirationInPermissionGroup');
                                $statement->execute(array(
                                    ':userID' => $data->action[3],
                                    ':groupName' => $value['groupName'],
                                    ':expires' => $expires
                                ));
                            }
                        }
                    } else {
                        // Remove user from group
                        $statement=$db->prepare('removeUserFromPermissionGroup');
                        $statement->execute(array(
                            ':userID' => $data->action[3],
                            ':groupName' => $value['groupName']
                        ));

                    }
                } else {
                    if($data->output['userForm']->sendArray[':'.$value['groupName']]) {
                        if($expires==0) {
                            // User is not a member and is being added to a group
                            $statement=$db->prepare('addUserToPermissionGroupNoExpires');
                            $statement->execute(array(
                                ':userID' => $data->action[3],
                                ':groupName' => $value['groupName']
                            ));
                        } else {
                            // User is not a member and is being added to a group
                            $statement=$db->prepare('addUserToPermissionGroup');
                            $statement->execute(array(
                                ':userID' => $data->action[3],
                                ':groupName' => $value['groupName'],
                                ':expires' => $expires
                            ));
                        }
                    } else {
                        // Do nothing
                    }
                }
                unset($data->output['userForm']->sendArray[':'.$value['groupName']]);
                unset($data->output['userForm']->sendArray[':'.$value['groupName'].'_expiration']);
                unset($data->output['userForm']->sendArray[':'.$value['groupName'].'_expiration_hidden']);
                unset($data->output['userForm']->sendArray[':'.$value['groupName'].'_update']);
            }

			//--Don't Need These, User Already Exists--//
			unset($data->output['userForm']->sendArray[':password2']);
			unset($data->output['userForm']->sendArray[':registeredDate']);
			unset($data->output['userForm']->sendArray[':registeredIP']);
			unset($data->output['userForm']->sendArray[':lastAccess']);

            unset($data->output['userForm']->sendArray[':id_hidden']);
            unset($data->output['userForm']->sendArray[':registeredDate_hidden']);
            unset($data->output['userForm']->sendArray[':registeredIP_hidden']);
            unset($data->output['userForm']->sendArray[':lastAccess_hidden']);
			/* existing user, from form, must be save existing */
			if ($_POST['viewUser_password']=='') {
				$statement=$db->prepare('updateUserByIdNoPw','admin_users');
				unset($data->output['userForm']->sendArray[':password']);
				$data->output['userForm']->sendArray[':id']=$data->action[3];
			} else {
				$data->output['userForm']->sendArray[':password']=hash('sha256',$_POST['viewUser_password']);
				$statement=$db->prepare('updateUserById','admin_users');
				$data->output['userForm']->sendArray[':id']=$data->action[3];
			}

			$result = $statement->execute($data->output['userForm']->sendArray);
			
			if($result == FALSE) {
				$data->output['savedOkMessage'] = 'There was an error in saving to the database';
				return;
			}
			
			$id = $db->lastInsertId();
			$profileAlbum = $db->prepare('addAlbum', 'gallery');
			$profileAlbum->execute(array(':user' => $id, ':name' => 'Profile Pictures', ':shortName' => 'profile-pictures', 'allowComments' => 0));
			
			if (empty($data->output['secondSidebar'])) {
				$data->output['savedOkMessage']='
					<h2>User <em>'.$data->output['userForm']->sendArray[':name'].'<em> Saved Successfully</h2>
					<div class="panel buttonList">
						<a href="'.$data->linkRoot.'admin/users/add">
							Add New User
						</a>
						<a href="'.$data->linkRoot.'admin/users/list/">
							Return to User List
						</a>
					</div>';
			}
		} else {
			/*
				invalid data, so we want to show the form again
			*/
			$data->output['secondSidebar']='
				<h2>Error in Data</h2>
				<p>
					There were one or more errors. Please correct the fields with the red X next to them and try again.
				</p>';
			if ($existing) {
				$data->output['secondSidebar'].='
				<p>
					<strong>Username Already Exists!</strong>
				</p>';
				$data->output['userForm']->fields['name']['error']=true;
			}
			if ($_POST['viewUser_password']!=$_POST['viewUser_password2']) {
				$data->output['secondSidebar'].='
				<p>
					<strong>Password fields do not match!</strong>
				</p>';
				$data->output['userForm']->fields['password']['error']=true;
				$data->output['userForm']->fields['password2']['error']=true;
			}
		}
	}
}
function admin_usersShow($data) {
	if (isset($data->output['pagesError']) && $data->output['pagesError'] == 'unknown function') {
		admin_unknown();
	} else if (isset($data->output['savedOkMessage'])) {
		echo $data->output['savedOkMessage'];
	} else {
		theme_buildForm($data->output['userForm']);
	}
}
?>
