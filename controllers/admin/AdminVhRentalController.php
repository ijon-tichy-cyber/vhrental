<?php

class AdminVhRentalController extends ModuleAdminController
{
    public function __construct()
    {
        $this->module = Module::getInstanceByName('vhrental');
        $this->bootstrap = true;

        parent::__construct();

        $this->page_header_toolbar_title = $this->l('Wypożyczanie terenów');
    }

    public function initContent()
    {
        // Aktualizujemy statusy przed wyświetleniem listy.
        $this->module->updateOverdueStatuses();

        $this->content = $this->module->getContent();

        parent::initContent();
    }
}
