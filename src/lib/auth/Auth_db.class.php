<?php
/* Copyright (c) Anuko International Ltd. https://www.anuko.com
License: See license.txt */

/**
* Auth_db class is used to authenticate users against internal DB
* @package TimeTracker
*/
class Auth_db extends Auth {

  /**
   * Authenticate user against internal users DB
   *
   * @param string $login
   * @param string $password
   * @return mixed
   */
  function authenticate($login, $password)
  {
    $mdb2 = getConnection();

    if (AUTH_DB_HASH_ALGORITHM !== '') {
      $sql = "SELECT id, password as hash FROM tt_users"." WHERE login = ".$mdb2->quote($login)." AND status = 1";
      $res = $mdb2->query($sql);
      if (is_a($res, 'PEAR_Error')) {
        die($res->getMessage());
      }
      $val = $res->fetchRow();
      if (isset($val['id']) && $val['id'] > 0) {
        if (password_verify($password, $val['hash'])) {
          if (password_needs_rehash($val['hash'], PASSWORD_ALGORITHM, AUTH_DB_HASH_ALGORITHM_OPTIONS)) {
            $sql = "update `tt_users` set `password` = '".password_hash($password, PASSWORD_ALGORITHM, AUTH_DB_HASH_ALGORITHM_OPTIONS)."' where `id` = " . $mdb2->quote($val['id']);
            $affected = $mdb2->exec($sql);
            if (is_a($res, 'PEAR_Error')) die($res->getMessage());
          }
          return array('login'=>$login,'id'=>$val['id']);
        }
      }
    }
    else {
      // md5 hash
      $sql = "SELECT id FROM tt_users"." WHERE login = ".$mdb2->quote($login)." AND password = md5(".$mdb2->quote($password).") AND status = 1";
      $res = $mdb2->query($sql);
      if (is_a($res, 'PEAR_Error')) {
        die($res->getMessage());
      }
      $val = $res->fetchRow();
      if (isset($val['id']) && $val['id'] > 0) {
        return array('login'=>$login,'id'=>$val['id']);
      }
    }
    return false;

    /*
    // Try md5 password match first.
    $sql = "SELECT id FROM tt_users"." WHERE login = ".$mdb2->quote($login)." AND password = md5(".$mdb2->quote($password).") AND status = 1";

    $res = $mdb2->query($sql);
    if (is_a($res, 'PEAR_Error')) {
      die($res->getMessage());
    }

    $val = $res->fetchRow();
    if (isset($val['id']) && $val['id'] > 0) {
      return array('login'=>$login,'id'=>$val['id']);
    } else {
      // If the OLD_PASSWORDS option is defined - set it.
      if (isTrue('OLD_PASSWORDS')) {
        $sql = "SET SESSION old_passwords = 1";
        $res = $mdb2->query($sql);
        if (is_a($res, 'PEAR_Error')) {
          die($res->getMessage());
        }
      }
      // Try legacy password match. This is needed for compatibility with older versions of TT.
      $sql = "SELECT id FROM tt_users
        WHERE login = ".$mdb2->quote($login)." AND password = old_password(".$mdb2->quote($password).") AND status = 1";
      $res = $mdb2->query($sql);
      if (is_a($res, 'PEAR_Error')) {
        return false; // Simply return false for a meaningful error message on screen, see the comment below.
        // die($res->getMessage()); // old_password() function is removed in MySQL 5.7.5.
                                    // We are getting a confusing "MDB2 Error: not found" in this case if we die.
        // TODO: perhaps it's time to simplify things and remove handling of old passwords completely.
        // HOWEVER: some users apparently never change their passwords. When I tried removing OLD_PASSWORDS
        // support in November 2018, there were login issues with such users.
      }
      $val = $res->fetchRow();
      if (isset($val['id']) && $val['id'] > 0) {
        return array('login'=>$login,'id'=>$val['id']);
      }
    }

    // Special handling for admin@localhost - search for an account with admin role with a matching password.
    if ($login == 'admin@localhost') {
      $sql = "SELECT u.id, u.login FROM tt_users u".
        " LEFT JOIN tt_roles r on (u.role_id = r.id)".
        " WHERE r.rank = 1024 AND password = md5(".$mdb2->quote($password).") AND u.status = 1";
      $res = $mdb2->query($sql);
      if (is_a($res, 'PEAR_Error')) {
        die($res->getMessage());
      }
      $val = $res->fetchRow();
      if (isset($val['id']) && $val['id'] > 0) {
        return array('login'=>$val['login'],'id'=>$val['id']);
      }
    }

    return false;
    */
  }

  function isPasswordExternal() {
    return false;
  }
}