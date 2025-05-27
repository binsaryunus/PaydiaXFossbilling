<?php

use Pimple\Container;

class Payment_Adapter_Paydia extends Payment_AdapterAbstract implements \FOSSBilling\InjectionAwareInterface
{
    protected ?Container $di = null;
    protected array $config = [];

    public function setDi(Container $di): void { $this->di = $di; }
    public function getDi(): ?Container { return $this->di; }

    public function __construct(array $config)
    {
        foreach (['client_id', 'client_secret', 'merchant_id', 'test_mode'] as $key) {
            if (empty($config[$key])) {
                throw new \Payment_Exception("Missing Paydia config key: $key");
            }
        }
        $this->config = $config;

        require_once __DIR__ . '/Paydia/lib/Config.php';
        require_once __DIR__ . '/Paydia/lib/Auth.php';
        require_once __DIR__ . '/Paydia/lib/Service.php';
        require_once __DIR__ . '/Paydia/lib/Mpm.php';
        require_once __DIR__ . '/Paydia/lib/Balance.php';
        require_once __DIR__ . '/Paydia/lib/CustomerTopup.php';
        require_once __DIR__ . '/Paydia/lib/TransferToBank.php';
        require_once __DIR__ . '/Paydia/lib/Util.php';
    }

    protected function isSandbox(): bool
    {
        return filter_var($this->config['test_mode'], FILTER_VALIDATE_BOOLEAN);
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description' => 'Terima pembayaran QRIS dinamis melalui Paydia SNAP.',
            'logo' => [
                'logo' => 'https://paydia.id/assets/images/logo.png',
                'height' => '60px',
                'width' => '120px',
            ],
            'form' => [
                'client_id' => ['text', ['label' => 'Client ID', 'required' => true]],
                'client_secret' => ['text', ['label' => 'Client Secret', 'required' => true]],
                'merchant_id' => ['text', ['label' => 'Merchant ID', 'required' => true]],
                'test_mode' => ['radio', ['label' => 'Mode Sandbox?', 'multiOptions' => ['1' => 'Ya', '0' => 'Tidak'], 'required' => true]],
            ],
        ];
    }

    public function getType(): string
    {
        return Payment_AdapterAbstract::TYPE_HTML;
    }

    public function getHtml($api_admin, $invoice_id, $subscription = null): string
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoice_id, 'Invoice not found');
        return $this->createPaymentForm($invoice);
    }

    public function singlePayment($invoice): string
    {
        return $this->createPaymentForm($invoice);
    }

    public function pay($invoice): string
    {
        return $this->createPaymentForm($invoice);
    }

    public function banklink($invoice): string
    {
        return $this->createPaymentForm($invoice);
    }

protected function createPaymentForm($invoice): string
    {
        date_default_timezone_set('Asia/Jakarta');
        $timestamp = date('Y-m-d\TH:i:sP');
        error_log('[Paydia] Local TIMESTAMP: ' . $timestamp);

        error_log('[Paydia] Gateway Config - client_id: ' . $this->config['client_id']);
        error_log('[Paydia] Gateway Config - client_secret: ' . substr($this->config['client_secret'], 0, 4) . '***');

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $this->config['client_id'])) {
            error_log('[Paydia] FORMAT ERROR: client_id mengandung karakter tidak valid');
            return 'Client ID tidak valid. Periksa format dan pastikan hanya huruf kapital, angka, - atau _';
        }

        \PaydiaSNAP\Config::setClientId($this->config['client_id']);
        \PaydiaSNAP\Config::setClientSecret($this->config['client_secret']);

        $tokenData = \PaydiaSNAP\Auth::getAccessTokenB2b($timestamp);
        error_log('[Paydia] getAccessTokenB2b() response: ' . json_encode($tokenData));

        if (empty($tokenData['access_token'])) {
            error_log('[Paydia] Missing access_token or failed response: ' . json_encode($tokenData));
            return 'Gagal mendapatkan access token dari Paydia.';
        }

        $token = $tokenData['access_token'];
        $orderId = 'INV-' . $invoice->id . '-' . time();
        $amount = number_format($invoice->total, 2, '.', '');
        $redirectUrl = method_exists($invoice, 'getParam') ? $invoice->getParam('redirect_url') : '';

        $service = new \PaydiaSNAP\Service($token, $this->config['merchant_id'], $this->isSandbox());
        $response = $service->createPayment([
            'order_id'     => $orderId,
            'amount_total' => $amount,
            'redirect_url' => $redirectUrl
        ]);

        error_log('[Paydia] createPayment response: ' . json_encode($response));

        if (!empty($response['qr_code_url']) && filter_var($response['qr_code_url'], FILTER_VALIDATE_URL)) {
            $qr = htmlspecialchars($response['qr_code_url'], ENT_QUOTES, 'UTF-8');
            return <<<HTML
<iframe allowtransparency="true" frameborder="0" scrolling="no" src="{$qr}" style="width:100%;min-height:600px" height="100%"></iframe>
HTML;
        }

        return '<div style="text-align:center">'
             . '<h3>QRIS tidak tersedia. Silakan coba beberapa saat lagi.</h3>'
             . '<pre>' . htmlspecialchars(json_encode($response), ENT_QUOTES, 'UTF-8') . '</pre>'
             . '</div>';
    }


    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (($input['status'] ?? '') !== '00') {
            return ['status' => 'failed'];
        }

        $orderId = (int)str_replace('INV-', '', explode('-', $input['order_id'])[1] ?? 0);
        $txnId = $input['transaction_id'] ?? '';
        $amount = (float)$input['amount_total'];

        $existingTx = $this->di['db']->findOne(
            'Transaction',
            'txn_id = ? AND gateway_id = ?',
            [$txnId, $gateway_id]
        );
        if ($existingTx && $existingTx->status === 'Complete') {
            return ['status' => 'ok'];
        }

        return $api_admin->invoice_transaction_update([
            'id'          => $orderId,
            'gateway_id'  => $gateway_id,
            'txn_id'      => $txnId,
            'amount'      => $amount,
            'currency'    => 'IDR',
            'txn_status'  => 'complete',
            'type'        => 'payment',
            'invoice_id'  => $orderId,
            'status'      => 'received',
            'validate_ipn'=> 1,
        ]) && $api_admin->invoice_mark_as_paid([
            'id'        => $orderId,
            'gateway_id'=> $gateway_id,
            'txn_id'    => $txnId,
        ]) ? ['status' => 'ok'] : ['status' => 'failed'];
    }
}
