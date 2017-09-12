<?php

/**
 * Simpla CMS
 *
 * @copyright 	2014 platron
 * @link 		http://platron.ru
 * @author 		Lashnev Alexey
 *
 * К этому скрипту обращается webmoney в процессе оплаты
 *
 */

$arrStatuses = array(
	'pending'	=> 0,
	'ok'		=> 1,
	'done'		=> 2,
	'deleted'	=> 3,
);

// Работаем в корневой директории
require_once('PG_Signature.php');
chdir ('../../');
require_once('api/Simpla.php');
$simpla = new Simpla();

$arrRequest = array();
if(!empty($_POST)) 
	$arrRequest = $_POST;
else
	$arrRequest = $_GET;

////////////////////////////////////////////////
// Выберем заказ из базы
////////////////////////////////////////////////
$order = $simpla->orders->get_order(intval($arrRequest['pg_order_id']));

////////////////////////////////////////////////
// Выбираем из базы соответствующий метод оплаты
////////////////////////////////////////////////
$method = $simpla->payment->get_payment_method(intval($order->payment_method_id));

$arrSettings = unserialize($method->settings);

$thisScriptName = PG_Signature::getOurScriptName();
if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, $arrSettings['secret_key']))
	die("Wrong signature");

if(!isset($arrRequest['pg_result'])){
	$bCheckResult = 0;
	if(empty($order) || $order->status != $arrStatuses['pending'])
		$error_desc = "Товар не доступен. Либо заказа нет, либо его статус " . array_search($order->status, $arrStatuses);	
	elseif(!check_order_items($simpla, $order))
		$error_desc = "Товара нет в наличии";	
	elseif($arrRequest['pg_amount'] != $simpla->money->convert($order->total_price, $method->currency_id, false) || $arrRequest['pg_amount']<=0)
		$error_desc = "Неверная сумма";
	else
		$bCheckResult = 1;
	
	$arrResponse['pg_salt']              = $arrRequest['pg_salt']; // в ответе необходимо указывать тот же pg_salt, что и в запросе
	$arrResponse['pg_status']            = $bCheckResult ? 'ok' : 'error';
	$arrResponse['pg_error_description'] = $bCheckResult ?  ""  : $error_desc;
	$arrResponse['pg_sig']				 = PG_Signature::make($thisScriptName, $arrResponse, $arrSettings['secret_key']);

	$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
	$objResponse->addChild('pg_salt', $arrResponse['pg_salt']);
	$objResponse->addChild('pg_status', $arrResponse['pg_status']);
	$objResponse->addChild('pg_error_description', $arrResponse['pg_error_description']);
	$objResponse->addChild('pg_sig', $arrResponse['pg_sig']);

}
else{
	$bResult = 0;
	if(empty($order) || !in_array($order->status, array($arrStatuses['pending'], $arrStatuses['ok'])))
		$strResponseDescription = "Товар не доступен. Либо заказа нет, либо его статус " . array_search($order->status, $arrStatuses);		
	elseif(!check_order_items($simpla, $order))
		$strResponseDescription = "Товара нет в наличии";
	elseif($arrRequest['pg_amount'] != $simpla->money->convert($order->total_price, $method->currency_id, false) || $arrRequest['pg_amount']<=0)
		$strResponseDescription = "Неверная сумма";
	else {
		$bResult = 1;
		$strResponseStatus = 'ok';
		$strResponseDescription = "Оплата принята";
		if ($arrRequest['pg_result'] == 1) {
			// Установим статус оплачен
			$simpla->orders->update_order(intval($order->id), array('paid'=>1));

			// Спишем товары  
			$simpla->orders->close(intval($order->id));
			$simpla->notify->email_order_user(intval($order->id));
			$simpla->notify->email_order_admin(intval($order->id));
		}
		else{
			// нет статуса не удачная оплата
		}
	}
	if(!$bResult)
		if($arrRequest['pg_can_reject'] == 1)
			$strResponseStatus = 'rejected';
		else
			$strResponseStatus = 'error';
	
	$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
	$objResponse->addChild('pg_salt', $arrRequest['pg_salt']); // в ответе необходимо указывать тот же pg_salt, что и в запросе
	$objResponse->addChild('pg_status', $strResponseStatus);
	$objResponse->addChild('pg_description', $strResponseDescription);
	$objResponse->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $objResponse, $arrSettings['secret_key']));
}

header("Content-type: text/xml");
echo $objResponse->asXML();
die();
 
////////////////////////////////////
// Проверка наличия товара
////////////////////////////////////
function check_order_items($simpla, $order){
	$purchases = $simpla->orders->get_purchases(array('order_id'=>intval($order->id)));
	foreach($purchases as $purchase)
	{
		$variant = $simpla->variants->get_variant(intval($purchase->variant_id));
		if(empty($variant) || (!$variant->infinity && $variant->stock < $purchase->amount))
			return false;
	}
	return true;
}