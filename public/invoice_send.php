<?php
/* Copyright (c) Anuko International Ltd. https://www.anuko.com
License: See license.txt */

require_once('initialize.php');
import('form.Form');
import('ttInvoiceHelper');
import('ttUserConfig');

// Access checks.
if (!(ttAccessAllowed('manage_invoices') || ttAccessAllowed('view_client_invoices'))) {
  header('Location: access_denied.php');
  exit();
}
if (!$user->isPluginEnabled('iv')) {
  header('Location: feature_disabled.php');
  exit();
}
$cl_invoice_id = (int)$request->getParameter('id');
$invoice = ttInvoiceHelper::getInvoice($cl_invoice_id);
if (!$invoice) {
  header('Location: access_denied.php');
  exit();
}
// End of access checks.

$uc = new ttUserConfig();

if ($request->isPost()) {
  $cl_receiver = is_null($request->getParameter('receiver')) ? '' : trim($request->getParameter('receiver'));
  $cl_cc = is_null($request->getParameter('cc')) ? '' : trim($request->getParameter('cc'));
  $cl_subject = is_null($request->getParameter('subject')) ? '' : trim($request->getParameter('subject'));
  $cl_comment = is_null($request->getParameter('comment')) ? '' : trim($request->getParameter('comment'));
} else {
  $cl_receiver = $uc->getValue(SYSC_LAST_INVOICE_EMAIL);
  $cl_cc = $uc->getValue(SYSC_LAST_INVOICE_CC);
  $cl_subject = $i18n->get('title.invoice').' '.$invoice['name'].', '.$user->group_name;
}

$form = new Form('mailForm');
$form->addInput(array('type'=>'hidden','name'=>'id','value'=>$cl_invoice_id));
$form->addInput(array('type'=>'text','name'=>'receiver','value'=>$cl_receiver));
$form->addInput(array('type'=>'text','name'=>'cc','value'=>$cl_cc));
$form->addInput(array('type'=>'text','name'=>'subject','value'=>$cl_subject));
$form->addInput(array('type'=>'textarea','name'=>'comment','maxlength'=>'250'));
$form->addInput(array('type'=>'submit','name'=>'btn_send','value'=>$i18n->get('button.send')));

if ($request->isPost()) {
  // Validate user input.
  if (!ttValidEmailList($cl_receiver)) $err->add($i18n->get('error.field'), $i18n->get('form.mail.to'));
  if (!ttValidEmailList($cl_cc, true)) $err->add($i18n->get('error.field'), $i18n->get('label.cc'));
  if (!ttValidString($cl_subject)) $err->add($i18n->get('error.field'), $i18n->get('label.subject'));
  if (!ttValidString($cl_comment, true)) $err->add($i18n->get('error.field'), $i18n->get('label.comment'));

  if ($err->no()) {
    // Save last invoice emails for future use.
    $uc->setValue(SYSC_LAST_INVOICE_EMAIL, $cl_receiver);
    $uc->setValue(SYSC_LAST_INVOICE_CC, $cl_cc);

    // Prepare report body.
    $body = ttInvoiceHelper::prepareInvoiceBody($cl_invoice_id, $cl_comment);

    $bcc = (!empty($user->bcc_email) ? $user->bcc_email : "");
    if (!send_mail($cl_receiver, "", $cl_subject, $body, $cl_cc, $bcc)) {
      $err->add($i18n->get('error.mail_send'));
    }
    else {
      $msg->add($i18n->get('form.mail.invoice_sent'));
    }
  }
} // isPost

$smarty->assign('title', $i18n->get('title.send_invoice'));
$smarty->assign('forms', array($form->getName()=>$form->toArray()));
$smarty->assign('onload', 'onload="document.mailForm.'.($cl_receiver?'comment':'receiver').'.focus()"');
$smarty->assign('content_page_name', 'mail.tpl');
$smarty->display('index.tpl');
