<?php

// ttSession classe is used to store sessions in databse
class ttSession {
  private $id = null;   // session id
  private $expire = 0;  // session expires
  private $data = null; // session datas
  private $db = null;   // db connection

  // Constructor.
  public function __construct() {
    // Set handler to overide SESSION
    session_set_save_handler(
      array($this, "_open"),
      array($this, "_close"),
      array($this, "_read"),
      array($this, "_write"),
      array($this, "_destroy"),
      array($this, "_gc")
    );
  }

  public function _open() {
    $this->db = getConnection();
    $this->_gc(PHPSESSID_TTL);
    return true;
  }

  public function _close() {
    $this->db = null;
    return true;
  }

  public function _read($id) {
    $id = preg_replace('/[^a-zA-Z0-9,\-]/', '', $id);
    $sql = "SELECT data FROM tt_sessions WHERE id = '".$id."'";
    $res = $this->db->query($sql);
    if (is_a($res, 'PEAR_Error')) {
      return ""; // Return an empty string
    }
    $row = $res->fetchRow();
    if (empty($row['data'])) {
      return ""; // Return an empty string
    }
    else {
      return $row['data'];
    }
  }

  public function _write($id, $data) {
    $id = preg_replace('/[^a-zA-Z0-9,\-]/', '', $id);
    $expire = time(); // Create time stamp
    $sql = "REPLACE INTO tt_sessions (id, expire, data) VALUES ('".$id."', $expire, '".$data."')";
    $affected = $this->db->exec($sql);
    if (is_a($affected, 'PEAR_Error')) {
      return false;
    }
    return true;
  }

  public function _destroy($id) {
    $id = preg_replace('/[^a-zA-Z0-9,\-]/', '', $id);
    $sql = "DELETE FROM tt_sessions WHERE id = '".$id."'";
    $affected = $this->db->exec($sql);
    if (is_a($affected, 'PEAR_Error')) {
      return false;
    }
    return true;
  }

  public function _gc($lifetime) {
    $old = time() - intval($lifetime);
    $sql = "DELETE FROM tt_sessions WHERE expire < $old";
    $affected = $this->db->exec($sql);
    if (is_a($affected, 'PEAR_Error')) {
      return false;
    }
    return true;
  }
}