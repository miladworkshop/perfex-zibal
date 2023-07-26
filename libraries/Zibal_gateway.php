<?php
defined('BASEPATH') or exit('No direct script access allowed');

class zibal_gateway extends App_gateway
{
    public function __construct()
    {
        /**
         * Call App_gateway __construct function
         */
        parent::__construct();
        /**
         * REQUIRED
         * Gateway unique id
         * The ID must be alpha/alphanumeric
         */
        $this->setId('zibal');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('زیبال');

        /**
         * Add gateway settings
        */
        $this->setSettings([
            [
                'name'      	=> 'merchant_id',
                'encrypted' 	=> true,
                'label'     	=> 'کد درگاه ( Merchant )',
			],
			[
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'شناسه پرداخت {invoice_number}',
            ],
            [
                'name'          => 'currencies',
                'label'         => 'settings_paymentmethod_currencies',
                'default_value' => 'IRT,IRR',
			],
		]);
    }

	public function zibal_encrypt($merchant, $string)
	{
		global $site;

		$output 		= false;
		$encrypt_method = "AES-256-CBC";
		$secret_key 	= "payment-{$merchant}-encrypt";
		$secret_iv 		= md5($secret_key);
		$key 			= hash('sha256', $secret_key);
		$iv 			= substr(hash('sha256', $secret_iv), 0, 16);

		$output 		= openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
		$output 		= base64_encode($output);

		return $output;
	}

    /**
     * REQUIRED FUNCTION
     * @param  array $data
     * @return mixed
     */
    public function process_payment($data)
    {
		$parameters = array(
			"merchant" 		=> $this->decryptSetting('merchant_id'),
			"callbackUrl" 	=> urlencode(site_url("zibal/callback?hash={$data['invoice']->hash}&inv={$data['invoiceid']}")),
			"amount" 		=> (isset($data['invoice']->currency_name) && strtoupper($data['invoice']->currency_name) == "IRT") ? preg_replace('~\.0+$~','', $data['amount']) * 10 : preg_replace('~\.0+$~','', $data['amount']),
			"orderId" 		=> format_invoice_number($data['invoice']->id),//
			"mobile" 		=> "",
		);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "https://gateway.zibal.ir/v1/request");
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
			// Save Session
			$this->ci->session->set_userdata([
				'zibal_payment_key' => $this->zibal_encrypt($parameters['merchant'], $parameters['amount']),
			]);

			// Add the token to database
			$this->ci->db->where('id', $data['invoiceid']);
			$this->ci->db->update(db_prefix().'invoices', ['token' => $response->trackId]);

			redirect("https://gateway.zibal.ir/start/{$response->trackId}");
		} else {
			$result_code 	= $response->result;
			$result_message = $response->message;

			set_alert('danger', "خطا در اتصال به درگاه پرداخت<br /><div style='text-align:left; direction:ltr;'>{$result_message}</div>");
			log_activity("zibal Payment Error [ Error CODE: {$result_code} Message: {$result_message} ]");
			redirect(site_url('invoice/' . $data['invoiceid'] . '/' . $data['invoice']->hash));
		}
    }
}