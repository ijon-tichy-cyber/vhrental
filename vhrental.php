<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class VhRental extends Module
{
    const BOXES_TABLE = 'vhrental_box';
    const TABLE = 'vhrental_loan';
    const CONF_API_KEY = 'VHRENTAL_API_KEY';
    const CONF_OVERDUE_HOUR = 'VHRENTAL_OVERDUE_HOUR';

    public function __construct()
    {
        $this->name = 'vhrental';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Vulgaris Magistralis';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Wypożyczanie pudeł z terenami');
        $this->description = $this->l('Ewidencja wypożyczeń pudeł przez klientów.');
        $this->confirmUninstall = $this->l('Czy na pewno chcesz odinstalować moduł? Spowoduje to usunięcie historii wypożyczeń.');
    }

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && Configuration::updateValue(self::CONF_API_KEY, bin2hex(random_bytes(32)))
            && Configuration::updateValue(self::CONF_OVERDUE_HOUR, 7)
            && $this->installVanaheimTab()
            && $this->installTab()
            && $this->positionVanaheimTab();
    }

    public function uninstall()
    {
        return $this->uninstallTab()
            && $this->uninstallDb()
            && Configuration::deleteByName(self::CONF_API_KEY)
            && Configuration::deleteByName(self::CONF_OVERDUE_HOUR)
            && parent::uninstall();
    }

    private function installDb()
    {
        $sql1 = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::BOXES_TABLE . '` (
            `id_box` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `symbol` VARCHAR(64) NOT NULL,
            PRIMARY KEY (`id_box`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        
        $sql2 = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_loan` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_box` INT UNSIGNED NOT NULL,
            `id_customer` INT UNSIGNED NOT NULL,
            `date_taken` DATETIME NOT NULL,
            `date_returned` DATETIME NULL,
            `status` ENUM("active","returned","overdue") NOT NULL DEFAULT "active",
            PRIMARY KEY (`id_loan`),
            KEY `idx_box_status` (`id_box`, `status`),
            KEY `idx_box` (`id_box`),
            KEY `idx_customer` (`id_customer`),
            KEY `idx_status` (`status`),
            KEY `idx_date_taken` (`date_taken`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return Db::getInstance()->execute($sql1) && Db::getInstance()->execute($sql2);
    }

    private function uninstallDb()
    {
      $res1 = Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::BOXES_TABLE . '`'
        );
      $res2 = Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`'
        );
        return $res1 && $res2;
    }

    public function getContent()
    {
        $this->updateOverdueStatuses();

        $output = '';

        if (Tools::isSubmit('submitBoxRentalSettings')) {
            $hour = (int) Tools::getValue('VHRENTAL_OVERDUE_HOUR');

            if ($hour < 0 || $hour > 24) {
                $output .= $this->displayError($this->l('Godzina musi być od 0 do 24.'));
            } else {
                Configuration::updateValue(self::CONF_OVERDUE_HOUR, $hour);
                $output .= $this->displayConfirmation($this->l('Ustawienia zapisane.'));
            }
        }

        if (Tools::isSubmit('vhrental_return')) {
            $idLoan = (int) Tools::getValue('id_loan');

            if ($this->returnLoan($idLoan)) {
                $output .= $this->displayConfirmation($this->l('Skrzynka została oznaczona jako zwrócona.'));
            } else {
                $output .= $this->displayError($this->l('Nie udało się oznaczyć wypożyczenia jako zwróconego.'));
            }
        }

        $output .= $this->renderSettings();
        $output .= $this->renderLoans();

        return $output;
    }

    private function renderSettings()
    {
        $apiKey = Configuration::get(self::CONF_API_KEY);
        $hour = (int) Configuration::get(self::CONF_OVERDUE_HOUR, 21);

        return '
        <div class="panel">
            <h3><i class="icon-cog"></i> ' . $this->l('Ustawienia') . '</h3>
            <form method="post">
                <div class="form-group">
                    <label class="control-label">' . $this->l('Przeterminowanie po (godzina)') . '</label>
                    <input type="number" min="1" max="24" name="VHRENTAL_OVERDUE_HOUR"
                           value="' . (int) $hour . '" class="form-control" style="max-width:180px">
                    <p class="help-block">' . $this->l('Po tej godzinie aktywne wypożyczenie otrzyma status „przeterminowana”.') . '</p>
                </div>
                <div class="form-group">
                    <label class="control-label">' . $this->l('Klucz API') . '</label>
                    <input type="text" readonly value="' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '" class="form-control">
                    <p class="help-block">' . $this->l('Używaj go w endpointach API jako parametr „key”.') . '</p>
                </div>
                <div class="form-group">
                    <label class="control-label">' . $this->l('CRONjob') . '</label>
                    <input type="text" readonly value="https://vanaheim.pl/module/vhrental/updateoverdue?key=' . htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') . '" class="form-control">
                    <p class="help-block">' . $this->l('CRON Ustawi statusy i wyśle powiadomienia. Najlepiej parę minut po godzinie przeterminowania.') . '</p>
                </div>
                <button type="submit" name="submitBoxRentalSettings" class="btn btn-default">
                    <i class="process-icon-save"></i> ' . $this->l('Zapisz') . '
                </button>
            </form>
        </div>';
    }

    private function renderLoans()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT l.*, c.firstname, c.lastname, c.email, b.symbol
             FROM `' . _DB_PREFIX_ . self::TABLE . '` l
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = l.id_customer
             LEFT JOIN `' . _DB_PREFIX_ . self::BOXES_TABLE.'` b ON b.id_box = l.id_box
             ORDER BY l.date_taken DESC
             LIMIT 100'
        );

        $html = '
        <div class="panel">
            <h3><i class="icon-archive"></i> ' . $this->l('Wypożyczenia') . '</h3>
            <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>' . $this->l('Skrzynka') . '</th>
                        <th>' . $this->l('Klient') . '</th>
                        <th>' . $this->l('Wypożyczono') . '</th>
                        <th>' . $this->l('Zwrócono') . '</th>
                        <th>' . $this->l('Status') . '</th>
                        <th></th>
                    </tr>
                </thead><tbody>';

        if (!$rows) {
            $html .= '<tr><td colspan="7">' . $this->l('Brak wypożyczeń.') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $customer = trim($row['firstname'] . ' ' . $row['lastname']);
                if (!$customer) {
                    $customer = '#' . (int) $row['id_customer'];
                }

                $badge = 'label-default';
                if ($row['status'] === 'active') {
                    $badge = 'label-success';
                } elseif ($row['status'] === 'overdue') {
                    $badge = 'label-danger';
                }

                $html .= '<tr>
                    <td>' . (int) $row['id_loan'] . '</td>
                    <td><strong>' . htmlspecialchars($row['id_box'], ENT_QUOTES, 'UTF-8') . '</strong><br><small>'.htmlspecialchars($row['symbol'], ENT_QUOTES, 'UTF-8').'</small></td>
                    <td>' . htmlspecialchars($customer, ENT_QUOTES, 'UTF-8') . '<br><small>' .
                        htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') . '</small></td>
                    <td>' . htmlspecialchars($row['date_taken'], ENT_QUOTES, 'UTF-8') . '</td>
                    <td>' . ($row['date_returned'] ? htmlspecialchars($row['date_returned'], ENT_QUOTES, 'UTF-8') : '-') . '</td>
                    <td><span class="label ' . $badge . '">' .
                        htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') . '</span></td>
                    <td>';

                if ($row['status'] !== 'returned') {
                    $html .= '<form method="post" style="display:inline">
                        <input type="hidden" name="id_loan" value="' . (int) $row['id_loan'] . '">
                        <button type="submit" name="vhrental_return" class="btn btn-default btn-xs"
                                onclick="return confirm(\'' . addslashes($this->l('Oznaczyć jako zwrócone?')) . '\')">
                            <i class="icon-check"></i> ' . $this->l('Ustaw Zwrócone') . '
                        </button>
                    </form>';
                }

                $html .= '</td></tr>';
            }
        }

        return $html . '</tbody></table></div></div>';
    }

    public function updateOverdueStatuses()
    {
        $hour = (int) Configuration::get(self::CONF_OVERDUE_HOUR, 21);
        $current_hour = (int)date('H');
        
        $db = Db::getInstance();
        $res = 0;
        
        //jeśli jest po overdue hour, to po prostu ustaw wszystko na overdue
        if ($current_hour >= $hour)
        {
          $db->execute(
              'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
               SET status = "overdue"
               WHERE status = "active"
               '
          );
          $res += $db->Affected_Rows();
        }
        
        
        //jeśli jakimś przypadkiem są stare wypożyczenia, też ustaw overdue
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
             SET status = "overdue"
             WHERE status = "active"
               AND date_taken < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
        $res += $db->Affected_Rows();
        return $res;
    }
    
    public function sendOverdueNotifications()
    {
      //STUB
      return 0;
    }

    public function getCurrentLoan($boxId)
    {
        $this->updateOverdueStatuses();

        return Db::getInstance()->getRow(
            "SELECT loan.*, c.firstname, c.lastname, c.email
             FROM `" . _DB_PREFIX_ . self::TABLE . "` loan
             LEFT JOIN `" . _DB_PREFIX_ . "customer` c ON c.id_customer = loan.id_customer
             WHERE loan.id_box = " . pSQL($boxId) . "
             AND loan.status IN ('active', 'overdue')
             ORDER BY loan.date_taken DESC
             "
        );
    }

    public function assignBox($boxId, $customerId)
    {
        $boxId = trim((string) $boxId);
        $customerId = (int) $customerId;

        if ($boxId <= 0 || $customerId <= 0 || !Validate::isUnsignedId($customerId) || !$this->getBoxById($boxId)) {
            return ['ok' => false, 'error' => 'Nieprawidłowa skrzynka lub klient.'];
        }
        
        if (!Validate::isLoadedObject(new Customer($customerId))) {
            return ['ok' => false, 'error' => 'Klient nie istnieje.'];
        }

        if ($this->getCurrentLoan($boxId)) {
            return ['ok' => false, 'error' => 'Skrzynka jest już wypożyczona.'];
        }
        
        $ok = Db::getInstance()->insert(self::TABLE, [
            'id_box' => pSQL($boxId),
            'id_customer' => $customerId,
            'date_taken' => date('Y-m-d H:i:s'),
            'date_returned' => null,
            'status' => 'active',
        ]);

        return $ok
            ? ['ok' => true, 'id_loan' => (int) Db::getInstance()->Insert_ID()]
            : ['ok' => false, 'error' => 'Nie udało się zapisać wypożyczenia.'];
    }

    public function returnLoan($idLoan)
    {
        $idLoan = (int) $idLoan;
        if ($idLoan <= 0) {
            return false;
        }

        return (bool) Db::getInstance()->update(
            self::TABLE,
            [
                'date_returned' => date('Y-m-d H:i:s'),
                'status' => 'returned',
            ],
            'id_loan = ' . $idLoan . ' AND status <> "returned"'
        );
    }
    
    public function returnLoanByBoxIdAndCustomer($boxId, $customer_id)
    {
      $loan = $this->getLoanByBoxAndCustomer($boxId, $customer_id);
      return $this->returnLoan($loan['id_loan']);
    }

    public function isCustomerLoan($idLoan, $customer_id)
    {
        $idLoan = (int) $idLoan;
        if ($idLoan <= 0 || $customer_id <= 0) {
            return false;
        }

        return (bool) Db::getInstance()->getRow(
            "SELECT loan.id_loan
             FROM `" . _DB_PREFIX_ . self::TABLE . "` loan
             LEFT JOIN `" . _DB_PREFIX_ . "customer` c ON c.id_customer = loan.id_customer
             WHERE loan.id_loan = '" . pSQL($idLoan) . "'
             AND loan.id_customer = '" . pSQL($customer_id) . "'
             AND loan.status IN ('active', 'overdue')
             ORDER BY loan.date_taken DESC
             "
        );
    }
    
    public function getLoanByBoxAndCustomer($boxId, $customer_id)
    {
      return Db::getInstance()->getRow(
            "SELECT loan.id_loan
             FROM `" . _DB_PREFIX_ . self::TABLE . "` loan
             LEFT JOIN `" . _DB_PREFIX_ . "customer` c ON c.id_customer = loan.id_customer
             WHERE loan.id_box = '" . pSQL($boxId) . "'
             AND loan.id_customer = '" . pSQL($customer_id) . "'
             AND loan.status IN ('active', 'overdue')
             ORDER BY loan.date_taken DESC
             "
        );
    }
    
    public function getBoxById($boxId)
    {
      return Db::getInstance()->getRow(
          "SELECT b.*
           FROM `" . _DB_PREFIX_ . self::BOXES_TABLE . "` b
           WHERE b.id_box = " . pSQL($boxId)
      );
    }
    
    public function getApiKey()
    {
        return (string) Configuration::get(self::CONF_API_KEY);
    }

    public function checkApiKey()
    {
        $provided = (string) Tools::getValue('key');
        $expected = $this->getApiKey();

        return $provided !== '' && $expected !== '' && hash_equals($expected, $provided);
    }

    private function installTab()
    {
        $idVanaheim = (int) Tab::getIdFromClassName('AdminVanaheim');

        if (!$idVanaheim) {
            return false;
        }
    
        $tab = new Tab();

        $tab->active = 1;
        $tab->class_name = 'AdminVhRental';
        $tab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Wypożyczanie terenów';
        }

        $tab->id_parent = $idVanaheim;

        $tab->module = $this->name;
        $tab->icon = 'inventory_2';

        return $tab->add();
    }
    
    private function installVanaheimTab()
    {
        $tab = new Tab();

        $tab->active = 1;
        $tab->class_name = 'AdminVanaheim';
        $tab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Vanaheim';
        }

        // Główny poziom menu
        $tab->id_parent = 0;
        $tab->module = $this->name;
        $tab->icon = 'store';

        return $tab->add();
    }
    
    private function positionVanaheimTab()
    {
        $idVanaheim = (int) Tab::getIdFromClassName('AdminVanaheim');
        $idSales = (int) Tab::getIdFromClassName('AdminParentOrders');

        if (!$idVanaheim || !$idSales) {
            return false;
        }

        $vanaheim = new Tab($idVanaheim);
        $sales = new Tab($idSales);

        $vanaheim->position = (int) $sales->position + 1;

        return $vanaheim->update();
    }

    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminVhRental');

        if (!$idTab) {
            return true;
        }

        $tab = new Tab($idTab);

        return $tab->delete();
    }
    
    



}
