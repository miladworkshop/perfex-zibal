<?php
defined('BASEPATH') or exit('No direct script access allowed');

class zibal extends App_Controller
{
	public function zibal_decrypt($merchant, $string)
	{
		global $site;

		$output 		= false;
		$encrypt_method = "AES-256-CBC";
		$secret_key 	= "payment-{$merchant}-encrypt";
		$secret_iv 		= md5($secret_key);
		$key 			= hash('sha256', $secret_key);
		$iv 			= substr(hash('sha256', $secret_iv), 0, 16);

		$output 		= openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);

		return $output;
	}

	public function callback()
    {
		$inv 			= $this->input->get('inv');
		$hash 			= $this->input->get('hash');
		$trackId 		= $this->input->get('trackId');
		$orderId 		= $this->input->get('orderId');
		$success 		= $this->input->get('success');

        check_invoice_restrictions($inv, $hash);

		$this->db->where('token', $trackId);
        $this->db->where('id', $inv);
        $db_token = $this->db->get(db_prefix().'invoices')->row()->token;

		$this->db->where('token', $trackId);
        $this->db->where('id', $inv);
		$currency_id = $this->db->get(db_prefix().'invoices')->row()->currency;
		
        $this->db->where('id', $currency_id);
		$currency_name = $this->db->get(db_prefix().'currencies')->row()->name;

        if ($db_token != $trackId)
		{
            set_alert('danger', 'توکن پرداخت معتبر نیست');
            redirect(site_url("invoice/{$inv}/{$hash}"));
        } else {
			$merchant_id = $this->zibal_gateway->decryptSetting('merchant_id');
			$amount 	= $this->zibal_decrypt($merchant_id, $this->session->userdata('zibal_payment_key'));
			$amount 	= (isset($currency_name) && strtoupper($currency_name) == "IRT") ? $amount / 10 : $amount;

			if(isset($success) && $success == 1)
			{
				$parameters = array(
					"merchant" 	=> $merchant_id,
					"trackId" 	=> $trackId,
				);

				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, "https://gateway.zibal.ir/v1/verify");
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($parameters));
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				$response  = curl_exec($ch);
				curl_close($ch);
				
				$response = json_decode($response);

				if ($response->result == 100) 
				{
					$success = $this->zibal_gateway->addPayment(
					[
						'amount'        => $amount,
						'invoiceid'     => $inv,
						'transactionid' => $trackId,
					]);

					set_alert('success', 'پرداخت شما با موفقیت انجام و ثبت شد');

					redirect(site_url("invoice/{$inv}/{$hash}"));
				} else {
					$result_code 	= $response->result;
					$result_message = $response->message;

					set_alert('danger', "خطا در اتصال به درگاه پرداخت<br /><div style='text-align:left; direction:ltr;'>{$result_message}</div>");
					log_activity("zibal Payment Error [ Error CODE: {$result_code} Message: {$result_message} ]");
					redirect(site_url("invoice/{$inv}/{$hash}"));
				}
			} else {
				set_alert('danger', 'پرداخت با شکست مواجه شد.');
				log_activity('zibal Payment Error [Error CODE: 0 Message: Transaction failed]');
				redirect(site_url("invoice/{$inv}/{$hash}"));
			}
		}
    }
}