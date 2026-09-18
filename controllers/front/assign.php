<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class VhRentalAssignModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        if (!$this->module->checkApiKey()) {
            $this->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        $customer_id = $this->context->customer->id;
        $box_id = Tools::getValue('box');
        if (!$customer_id) 
        {
          $this->json(['ok' => false, 'error' => 'Zaloguj się w Vanaheim'], 401);
        }
      
        if (!$box_id) {
            $this->json(['ok' => false, 'error' => 'Wymagany jest parametr box.'], 400);
        }
        
        if (!Validate::isUnsignedId($box_id) || !$this->module->getBoxById($box_id)) {
            $this->json(['ok' => false, 'error' => 'Nie ma takiego pudła'], 400);
        }

        // Modyfikacja danych tylko przez POST.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'error' => 'Użyj metody POST.'], 405);
        }

        $result = $this->module->assignBox(
            Tools::getValue('box'),
            (int) $customer_id
        );

        $this->json($result, $result['ok'] ? 200 : 409);
    }

    private function json(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
