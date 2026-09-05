<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\support\CreditService;

class CreditPricingController extends BaseController
{
    public function index()
    {
        $this->requireAdmin();
        CreditService::ensureSchema();

        return successCode(CreditService::pricingCatalog());
    }
}
