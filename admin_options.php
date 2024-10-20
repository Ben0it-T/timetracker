<?php
/* Copyright (c) Anuko International Ltd. https://www.anuko.com
License: See license.txt */

require_once('initialize.php');
import('form.Form');
import('ttUserHelper');
import('ttAdmin');

// Access check.
if (!ttAccessAllowed('administer_site')) {
  header('Location: access_denied.php');
  exit();
}
// End of access checks.

$cl_name = $cl_login = $cl_password1 = $cl_password2 = $cl_email = '';
if ($request->isPost()) {
  $cl_name = is_null($request->getParameter('name')) ? '' : trim($request->getParameter('name'));
  $cl_login = is_null($request->getParameter('login')) ? '' : trim($request->getParameter('login'));
  if (!$auth->isPasswordExternal()) {
    $cl_password1 = is_null($request->getParameter('password1')) ? '' : $request->getParameter('password1');
    $cl_password2 = is_null($request->getParameter('password2')) ? '' : $request->getParameter('password2');
  }
  $cl_email = is_null($request->getParameter('email')) ? '' : trim($request->getParameter('email'));
} else {
  $cl_name = $user->name;
  $cl_login = $user->login;
  $cl_email = $user->email;
}

$form = new Form('optionsForm');
$form->addInput(array('type'=>'text','maxlength'=>'100','name'=>'name','value'=>$cl_name));
$form->addInput(array('type'=>'text','minlength'=> AUTH_DB_LOGIN_MINLENGTH,'maxlength'=>'80','name'=>'login','value'=>$cl_login));
if (!$auth->isPasswordExternal()) {
  $form->addInput(array('type'=>'password','minlength'=>AUTH_DB_PWD_MINLENGTH,'maxlength'=>'128','name'=>'password1','value'=>$cl_password1));
  $form->addInput(array('type'=>'password','minlength'=>AUTH_DB_PWD_MINLENGTH,'maxlength'=>'128','name'=>'password2','value'=>$cl_password2));
}
$form->addInput(array('type'=>'text','maxlength'=>'100','name'=>'email','value'=>$cl_email));
$form->addInput(array('type'=>'submit','name'=>'btn_submit','value'=>$i18n->get('button.submit')));

if ($request->isPost()) {
  // Validate user input.
  if (!ttValidString($cl_name))
    $err->add($i18n->get('error.field'), $i18n->get('label.person_name'));
  if (!ttValidString($cl_login))
    $err->add($i18n->get('error.field'), $i18n->get('label.login'));
  if (AUTH_MODULE == 'db' && mb_strlen($cl_login) < AUTH_DB_LOGIN_MINLENGTH)
    $err->add($i18n->get('error.field'), $i18n->get('label.login'));
  // If we change login, it must be unique.
  if ($cl_login != $user->login && ttUserHelper::getUserByLogin($cl_login))
    $err->add($i18n->get('error.user_exists'));
  if (!$auth->isPasswordExternal() && ($cl_password1 != null || $cl_password2 != null)) {
      if (!ttValidString($cl_password1))
        $err->add($i18n->get('error.field'), $i18n->get('label.password'));
      if (!ttValidString($cl_password2))
        $err->add($i18n->get('error.field'), $i18n->get('label.confirm_password'));
      if ($cl_password1 !== $cl_password2)
        $err->add($i18n->get('error.not_equal'), $i18n->get('label.password'), $i18n->get('label.confirm_password'));
      if (AUTH_MODULE == 'db' && mb_strlen($cl_password1) < AUTH_DB_PWD_MINLENGTH)
        $err->add($i18n->get('error.weak_password'));
    }
  if (!ttValidEmail($cl_email, true))
    $err->add($i18n->get('error.field'), $i18n->get('label.email'));

  if ($err->no() && ttAdmin::updateSelf(array('name' => $cl_name,
    'login' => $cl_login,
    'password1' => $cl_password1,
    'password2' => $cl_password2,
    'email' => $cl_email))) {
    header('Location: admin_groups.php');
    exit();
  }
} // isPost

$smarty->assign('auth_external', $auth->isPasswordExternal());
$smarty->assign('forms', array($form->getName()=>$form->toArray()));
$smarty->assign('title', $i18n->get('title.options'));
$smarty->assign('content_page_name', 'admin_options.tpl');
$smarty->display('index.tpl');
