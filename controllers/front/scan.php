<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class VhRentalScanModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        if (!$this->module->checkApiKey()) {
            $this->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'error' => 'Użyj metody POST.'], 405);
        }
        
        $box = Tools::getValue('box');
        if (!$box) {
            $this->json(['ok' => false, 'error' => 'Brak parametru box.'], 400);
        }
        
        if (!Validate::isUnsignedId($box) || !$this->module->getBoxById($box)) {
            $this->json(['ok' => false, 'error' => 'Nie ma takiego pudła'], 400);
        }

        $loan = $this->module->getCurrentLoan($box);

        if (!$loan) {
            $this->json([
                'ok' => true,
                'box' => $box,
                'rented' => false,
//                'tenant' => null,
            ]);
        }

        $this->json([
            'ok' => true,
            'box' => $box,
            'rented' => true,
            'tenant' => [
//                'id_customer' => (int) $loan['id_customer'],
                'firstname' => $loan['firstname'],
//                'lastname' => $loan['lastname'],
//                'date_taken' => $loan['date_taken'],
//                'status' => $loan['status'],
            ],
        ]);
    }

    private function json(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
