<?php
/* Copyright (c) Anuko International Ltd. https://www.anuko.com
License: See license.txt */

import('ttUserHelper');
import('ttRoleHelper');

// ttRegistrator class is used to register a user in Time Tracker.
class ttRegistrator {
  var $user_name = null;  // User name.
  var $login = null;      // User login.
  var $password = null;   // User password.
  var $email = null;      // User email.
  var $group_name = null; // Group name.
  var $currency = null;   // Currency.
  var $lang = null;       // Language.
  var $group_id = null;   // Group id, set after we create a group.
  var $org_id = null;     // Organization id, the same as group_id (top group in org).
  var $role_id = null;    // Role id for top managers.
  var $user_id = null;    // User id after registration.
  var $err = null;        // Error object, passed to us as reference.
                          // We use it to communicate errors to caller.
  var $password1 = null;
  var $password2 = null;

  // Constructor.
  function __construct($fields, &$err) {
    $this->user_name = $fields['user_name'];
    $this->login = $fields['login'];    
    $this->password1 = $fields['password1'];
    $this->password2 = $fields['password2'];
    $this->email = $fields['email'];
    $this->group_name = $fields['group_name'];
    $this->currency = $fields['currency'];
    $this->lang = $fields['lang'];
    if (!$this->lang) $this->lang = 'en';
    $this->err = $err;

    // Validate passed in parameters.
    $this->validate();
  }

  function validate() {
    global $i18n;

    if (!ttValidString($this->group_name, false, MAX_NAME_CHARS))
      $this->err->add($i18n->get('error.field'), $i18n->get('label.group_name'));
    if (!ttValidString($this->currency, true, MAX_CURRENCY_CHARS))
      $this->err->add($i18n->get('error.field'), $i18n->get('label.currency'));
    if (!ttValidString($this->user_name, false, MAX_NAME_CHARS))
      $this->err->add($i18n->get('error.field'), $i18n->get('label.manager_name'));
    if (!ttValidString($this->login, false, MAX_NAME_CHARS))
      $this->err->add($i18n->get('error.field'), $i18n->get('label.manager_login'));
    if (AUTH_MODULE == 'db' && mb_strlen($this->login) < AUTH_DB_LOGIN_MINLENGTH)
      $this->err->add($i18n->get('error.field'), $i18n->get('label.manager_login'));
    if (!ttValidString($this->password1))
      $this->err->add($i18n->get('error.field'), $i18n->get('label.password'));
    if (!ttValidString($this->password2))
      $this->err->add($i18n->get('error.field'), $i18n->get('label.confirm_password'));
    if ($this->password1 !== $this->password2)
      $this->err->add($i18n->get('error.not_equal'), $i18n->get('label.password'), $i18n->get('label.confirm_password'));
    if (AUTH_MODULE == 'db' && mb_strlen($this->password1) < AUTH_DB_PWD_MINLENGTH)
      $this->err->add($i18n->get('error.weak_password'));
    if (!ttValidEmail($this->email, !isTrue('EMAIL_REQUIRED')))
      $this->err->add($i18n->get('error.field'), $i18n->get('label.email'));
    if (!ttUserHelper::canAdd())
      $this->err->add($i18n->get('error.user_count'));
  }

  // The register function registers a user in Time Tracker.
  function register() {
    if ($this->err->yes()) return false; // There are errors, do not proceed.

    global $i18n;
    global $user;

    // Protection from too many recent bot registrations from user IP.
    if ($this->registeredRecently()) {
      $this->err->add($i18n->get('error.registered_recently'));
      $this->err->add($i18n->get('error.access_denied'));
      return false;
    }

    import('ttUserHelper');
    if (ttUserHelper::getUserByLogin($this->login)) {
      // User login already exists.
      $this->err->add($i18n->get('error.user_exists'));
      return false;
    }

    // Create a new group.
    $this->group_id = $this->createGroup();
    $this->org_id = $this->group_id;
    if (!$this->group_id) {
      $this->err->add($i18n->get('error.db'));
      return false;
    }

    if (!ttRoleHelper::createPredefinedRoles($this->group_id, $this->lang)) {
      $err->add($i18n->get('error.db'));
      return false;
    }
    $this->role_id = ttRoleHelper::getTopManagerRoleID();
    $this->user_id = $this->createUser();

    if (!$this->user_id) {
      $this->err->add($i18n->get('error.db'));
      return false;
    }

    // Set created_by appropriately.
    if (!$this->setCreatedBy($this->user_id))
      return false;

    return true;
  }

  // The createGroup function creates a group in Time Tracker as part
  // of user registration process. This is a top group for user as top manager.
  function createGroup() {
    $mdb2 = getConnection();
    // Insert Group
    $types = array('text', 'text', 'text', 'text', 'text', 'timestamp', 'text');
    $sth = $mdb2->prepare('INSERT INTO tt_groups (group_key, name, currency, lang, plugins, created, created_ip) VALUES (:groupKey, :groupName, :groupCurrency, :groupLang, :groupPlugins, :groupCreated, :groupCreatedIp)', $types);
    $data = array(
      'groupKey' => ttRandomString(),
      'groupName' => $this->group_name,
      'groupCurrency' => $this->currency,
      'groupLang' => $this->lang,
      'groupPlugins' => defined('DEFAULT_PLUGINS') ? DEFAULT_PLUGINS : null,
      'groupCreated' => date("Y-m-d H:i:s"),
      'groupCreatedIp' => $_SERVER['REMOTE_ADDR']
    );
    $affected = $sth->execute($data);

    if (is_a($affected, 'PEAR_Error')) return false;
    
    // Update org_id
    $group_id = $mdb2->lastInsertID('tt_groups', 'id');
    $types = array('integer');
    $sth = $mdb2->prepare('UPDATE tt_groups SET org_id=:groupId WHERE org_id is NULL AND  id=:groupId', $types);
    $data = array('groupId' => $group_id);
    $affected = $sth->execute($data);
    if (is_a($affected, 'PEAR_Error')) return false;

    return $group_id;
  }

  // The createUser creates a user in database as part of registration process.
  function createUser() {
    $mdb2 = getConnection();
    if (AUTH_DB_HASH_ALGORITHM !== '') {
      $password = password_hash($this->password1, PASSWORD_ALGORITHM, AUTH_DB_HASH_ALGORITHM_OPTIONS);
    }
    else {
      // md5 hash
      $password = md5($this->password1);
    }
    $types = array('text', 'text', 'text', 'integer', 'integer', 'timestamp', 'text', 'timestamp', 'text');
    $sth = $mdb2->prepare('INSERT INTO tt_users (login, password, name, group_id, org_id, role_id, email, created, created_ip) VALUES (:login, :password, :name, :groupId, :orgId, :roleId, :email, :created, :createdIp)', $types);
    $data = array(
      'login' => $this->login,
      'password' => $password,
      'name' => $this->user_name,
      'groupId' => $this->group_id,
      'orgId' => $this->org_id,
      'roleId' => $this->role_id,
      'email' => $this->email,
      'created' => date("Y-m-d H:i:s"),
      'createdIp' => $_SERVER['REMOTE_ADDR']
    );
    $affected = $sth->execute($data);

    if (!is_a($affected, 'PEAR_Error')) {
      $user_id = $mdb2->lastInsertID('tt_users', 'id');
      return $user_id;
    }
    return false;
  }

  // The setCreatedBy sets created_by field for both group and user to passed in user_id.
  private function setCreatedBy($user_id) {
    if ($this->err->yes()) return false; // There are errors, do not proceed.

    global $i18n;
    $mdb2 = getConnection();

    // Update group.
    $types = array('integer', 'integer', 'integer');
    $sth = $mdb2->prepare('UPDATE tt_groups SET created_by=:usrId WHERE id=:grpId AND org_id=:orgId', $types);
    $data = array(
      'usrId' => $user_id,
      'grpId' => $this->group_id,
      'orgId' => $this->org_id
    );
    $affected = $sth->execute($data);

    if (is_a($affected, 'PEAR_Error')) {
      $this->err->add($i18n->get('error.db'));
      return false;
    }

    // Update top manager.
    $types = array('integer', 'integer', 'integer', 'integer');
    $sth = $mdb2->prepare('UPDATE tt_users SET created_by=:userId WHERE id=:usrId AND group_id=:grpId AND org_id=:orgId', $types);
    $data = array(
      'userId' => $user_id,
      'usrId' => $this->user_id , 
      'grpId' => $this->group_id,
      'orgId' => $this->org_id
    );
    $affected = $sth->execute($data);
    if (is_a($affected, 'PEAR_Error')) {
      $this->err->add($i18n->get('error.db'));
      return false;
    }

    return true;
  }

  // registeredRecently determines if we already have successful recent registration(s) from user IP.
  // "recent" means the following:
  // - 2 or more registrations during last 15 minutes, or
  // - 1 registration during last minute.
  //
  // This offers some level of protection from bot registrations.
  function registeredRecently() {
    $mdb2 = getConnection();

    $types = array('text');
    $sth = $mdb2->prepare('SELECT count(*) as cnt FROM tt_groups WHERE created_ip=:createdIp AND created > now() - interval 15 minute', $types);
    $data = array('createdIp' => $_SERVER['REMOTE_ADDR']);
    $res = $sth->execute($data);
    if (is_a($res, 'PEAR_Error'))
      return false;
    $val = $res->fetchRow();
    if ($val['cnt'] == 0)
      return false; // No registrations in last 15 minutes.
    if ($val['cnt'] >= 2)
      return true;  // 2 or more registrations in last 15 mintes.

    // If we are here, there was exactly one registration during last 15 minutes.
    // Determine if it occurred within the last minute in a separate query.
    $types = array('text');
    $sth = $mdb2->prepare('SELECT created FROM tt_groups WHERE created_ip=:createdIp AND created > now() - interval 1 minute', $types);
    $data = array('createdIp' => $_SERVER['REMOTE_ADDR']);
    $res = $sth->execute($data);
    if (is_a($res, 'PEAR_Error'))
      return false;
    $val = $res->fetchRow();
    if ($val)
      return true;

    return false;
  }
}
