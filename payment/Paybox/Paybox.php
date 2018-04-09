<?php

require_once('api/Simpla.php');
require_once('PG_Signature.php');

class Paybox extends Simpla
{
	public function checkout_form($order_id, $button_text = null)
	{
		if(empty($button_text))
			$button_text = 'Перейти к оплате';

		$order = $this->orders->get_order((int)$order_id);
		$payment_method = $this->payment->get_payment_method($order->payment_method_id);
		$payment_settings = $this->payment->get_payment_settings($payment_method->id);
		$payment_currency = $this->money->get_currency($payment_method->currency_id);

		$arrOrderItems = $this->orders->get_purchases(array('order_id'=>intval($order->id)));
		$strDescription = '';
		foreach($arrOrderItems as $objItem){
			$strDescription .= $objItem->product_name;
			if($objItem->amount > 1)
				$strDescription .= "*".$objItem->amount;
			$strDescription .= "; ";
		}

		$result_url = $this->config->root_url.'/order/'.$order->url;
		$server_url = $this->config->root_url.'/payment/Paybox/callback.php';

		$arrFields = array(
			'pg_merchant_id'		=> $payment_settings['merchant_id'],
			'pg_order_id'			=> $order_id,
			'pg_currency'			=> $payment_currency->code,
			'pg_amount'				=> $order->total_price,
			'pg_lifetime'			=> ($payment_settings['lifetime'])?$payment_settings['lifetime']*60:0,
			'pg_testing_mode'		=> ($payment_settings['testmode'] == 'test')? 1 : 0 ,
			'pg_description'		=> $strDescription,
			'pg_user_ip'			=> $_SERVER['REMOTE_ADDR'],
			'pg_language'			=> $payment_settings['language'],
			'pg_check_url'			=> $server_url,
			'pg_result_url'			=> $server_url,
			'pg_success_url'		=> $result_url,
			'pg_failure_url'		=> $result_url,
			'pg_request_method'		=> 'GET',
			'cms_payment_module'	=> 'SIMPLA',
			'pg_salt'				=> rand(21,43433), // Параметры безопасности сообщения. Необходима генерация pg_salt и подписи сообщения.
		);

		if(!empty($order->phone)){
			preg_match_all("/\d/", $order->phone, $array);
			$strPhone = implode('',@$array[0]);
			$arrFields['pg_user_phone'] = $strPhone;
		}

		if(!empty($order->email)){
			$arrFields['pg_user_email'] = $order->email;
			$arrFields['pg_user_contact_email'] = $order->email;
		}

		if(!empty($payment_settings['payment_system']))
			$arrFields['pg_payment_system'] = $payment_settings['payment_system'];

		$arrFields['pg_sig'] = PG_Signature::make('payment.php', $arrFields, $payment_settings['secret_key']);

		$strForm = "<form action='https://api.paybox.money/payment.php' method=POST>";

		foreach($arrFields as $strParamName => $strParamValue){
			$strForm .= "<input type=hidden name='$strParamName' value='$strParamValue'>";
		}
		$strForm .= "<input type=submit class=checkout_button value='Перейти к оплате &#8594;'></form>";

		return $strForm;
	}

}
