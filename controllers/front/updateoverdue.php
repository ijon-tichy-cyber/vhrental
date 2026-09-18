<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class VhRentalUpdateoverdueModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        if (!$this->module->checkApiKey()) {
            $this->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        $this->json(['ok' => true, 'loans_marked_overdue' => $this->module->updateOverdueStatuses(), 'notifications_sent' => $this->module->sendOverdueNotifications()]);
    }

    private function json(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
