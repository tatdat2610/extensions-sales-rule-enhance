<?php

namespace Extensions\SalesRuleEnhance\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Config
 *
 * @package Extensions\SalesRuleEnhance\Helper
 */
class Config extends AbstractHelper
{
    /**
     * @var string
     */
    const XML_PATH_SORT_PROMOTION_BY_MOST_SAVING = 'sales_rule_enhanced/general/enable_most_saving';

    /**
     * @return bool
     */
    public function useSortByMostSaving()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SORT_PROMOTION_BY_MOST_SAVING, ScopeInterface::SCOPE_WEBSITE);
    }
}
