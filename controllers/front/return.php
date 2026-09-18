<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class VhRentalReturnModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        if (!$this->module->checkApiKey()) {
            $this->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        
        $customer_id = $this->context->customer->id;
        if (!$customer_id) 
        {
          $this->json(['ok' => false, 'error' => 'Zaloguj się w Vanaheim'], 401);
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'error' => 'Użyj metody POST.'], 405);
        }
        
        $idBox = (int) Tools::getValue('box');
        
        if ($loan = $this->module->getLoanByBoxAndCustomer($idBox, $customer_id))
        {
          $ok = $this->module->returnLoanByBoxIdAndCustomer($idBox, $customer_id);
        }
        else
        {
          $ok = false;
        }

        $this->json(
            $ok
                ? ['ok' => true, 'id_loan' => $loan['id_loan']]
                : ['ok' => false, 'error' => 'Nie znaleziono aktywnego wypożyczenia.'],
            $ok ? 200 : 404
        );
    }

    private function json(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
