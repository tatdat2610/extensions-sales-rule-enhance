<?php

namespace Extensions\SalesRuleEnhance\Plugin\Magento\SalesRule\Model;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Api\Data\DiscountDataInterfaceFactory;
use Magento\SalesRule\Api\Data\RuleDiscountInterfaceFactory;
use Magento\SalesRule\Model\Quote\ChildrenValidationLocator;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Action\Discount\CalculatorFactory;
use Magento\SalesRule\Model\Rule\Action\Discount\DataFactory;
use Magento\SalesRule\Model\RulesApplier;
use Magento\SalesRule\Model\Utility;
use Extensions\SalesRuleEnhance\Helper\Config;

/**
 * Class RulesApplierPlugin
 *
 * @package Extensions\SalesRuleEnhance\Plugin\Magento\SalesRule\Model
 */
class RulesApplierPlugin
{
    /**
     * Application Event Dispatcher
     *
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @var \Magento\SalesRule\Model\Utility
     */
    protected $validatorUtility;

    /**
     * @var ChildrenValidationLocator
     */
    private $childrenValidationLocator;

    /**
     * @var CalculatorFactory
     */
    private $calculatorFactory;

    /**
     * @var \Magento\SalesRule\Model\Rule\Action\Discount\DataFactory
     */
    protected $discountFactory;

    /**
     * @var RuleDiscountInterfaceFactory
     */
    private $discountInterfaceFactory;

    /**
     * @var DiscountDataInterfaceFactory
     */
    private $discountDataInterfaceFactory;

    /**
     * @var array
     */
    private $discountAggregator;

    /**
     * @var Config
     */
    private $helperConfig;

    /**
     * RulesApplierPlugin constructor.
     *
     * @param CalculatorFactory $calculatorFactory
     * @param ManagerInterface $eventManager
     * @param Utility $utility
     * @param Config $helperConfig
     * @param ChildrenValidationLocator|null $childrenValidationLocator
     * @param DataFactory|null $discountDataFactory
     * @param RuleDiscountInterfaceFactory|null $discountInterfaceFactory
     * @param DiscountDataInterfaceFactory|null $discountDataInterfaceFactory
     */
    public function __construct(
        CalculatorFactory $calculatorFactory,
        ManagerInterface $eventManager,
        Utility $utility,
        Config $helperConfig,
        ChildrenValidationLocator $childrenValidationLocator = null,
        DataFactory $discountDataFactory = null,
        RuleDiscountInterfaceFactory $discountInterfaceFactory = null,
        DiscountDataInterfaceFactory $discountDataInterfaceFactory = null
    ) {
        $this->calculatorFactory = $calculatorFactory;
        $this->validatorUtility = $utility;
        $this->helperConfig = $helperConfig;
        $this->eventManager = $eventManager;
        $this->childrenValidationLocator = $childrenValidationLocator
            ?: ObjectManager::getInstance()->get(ChildrenValidationLocator::class);
        $this->discountFactory = $discountDataFactory ?: ObjectManager::getInstance()->get(DataFactory::class);
        $this->discountInterfaceFactory = $discountInterfaceFactory
            ?: ObjectManager::getInstance()->get(RuleDiscountInterfaceFactory::class);
        $this->discountDataInterfaceFactory = $discountDataInterfaceFactory
            ?: ObjectManager::getInstance()->get(DiscountDataInterfaceFactory::class);
    }

    /**
     * @param RulesApplier $object
     * @param callable $process
     * @param $item
     * @param $rules
     * @param $skipValidation
     * @param $couponCode
     *
     * @return array
     */
    public function aroundApplyRules(
        RulesApplier $object,
        callable $process,
        $item,
        $rules,
        $skipValidation,
        $couponCode
    ) {
        if (!$this->helperConfig->useSortByMostSaving()) {
            return $process($item, $rules, $skipValidation, $couponCode);
        }
        $address = $item->getAddress();
        $appliedRuleIds = [];
        $ruleDiscount = [];
        $ruleDiscountWithCoupon = [];
        foreach ($rules as $rule) {
            if (!$this->validatorUtility->canProcessRule($rule, $address)) {
                continue;
            }

            if (!$skipValidation && !$rule->getActions()->validate($item)) {
                if (!$this->childrenValidationLocator->isChildrenValidationRequired($item)) {
                    continue;
                }
                $childItems = $item->getChildren();
                $isContinue = true;
                if (!empty($childItems)) {
                    foreach ($childItems as $childItem) {
                        if ($rule->getActions()->validate($childItem)) {
                            $isContinue = false;
                        }
                    }
                }
                if ($isContinue) {
                    continue;
                }
            }
            $discountData = $this->getDiscountData($item, $rule);
            if ($rule->getCouponType() != Rule::COUPON_TYPE_NO_COUPON) {
                /**
                 * Highest priority is coupon code that customer inserted
                 */
                $ruleDiscountWithCoupon[] = [
                    'item' => $item,
                    'rule' => $rule,
                    'address' => $address,
                    'coupon_code' => $couponCode,
                    'discount_data' => $discountData,
                    'amount' => $discountData->getAmount()
                ];
            } else {
                $ruleDiscount[] = [
                    'item' => $item,
                    'rule' => $rule,
                    'address' => $address,
                    'coupon_code' => $couponCode,
                    'discount_data' => $discountData,
                    'amount' => $discountData->getAmount()
                ];
            }
        }
        /**
         * Sort promotion by most saving
         */
        usort($ruleDiscount, [$this, 'sortRules']);
        usort($ruleDiscountWithCoupon, [$this, 'sortRules']);
        /**
         * Merge promotions without coupon to promotion has coupon to make sure promotion has coupon is highest priority
         */
        $ruleDiscounts = array_merge($ruleDiscountWithCoupon, $ruleDiscount);
        $this->discountAggregator = [];
        /**
         * Apply promotion to item after sorting
         */
        foreach ($ruleDiscounts as $data) {
            $ruleId = $rule->getRuleId();
            $item = $data['item'];
            $rule = $data['rule'];
            $address = $data['address'];
            $couponCode = $data['coupon_code'];
            $discountData = $data['discount_data'];
            $this->applySortedRule($item, $rule, $address, $couponCode, $discountData, $object);
            $appliedRuleIds[$ruleId] = $ruleId;

            if ($rule->getStopRulesProcessing()) {
                break;
            }
        }

        return $appliedRuleIds;
    }

    /**
     * @param $rule1
     * @param $rule2
     *
     * @return int
     */
    private function sortRules($rule1, $rule2)
    {
        return ($rule1['amount'] > $rule2['amount']) ? -1 : 1;
    }

    /**
     * Apply rule
     *
     * @param $item
     * @param $rule
     * @param $address
     * @param $couponCode
     * @param $discountData
     * @param $object
     *
     * @return $this
     */
    protected function applySortedRule($item, $rule, $address, $couponCode, $discountData, $object)
    {
        $qty = $this->validatorUtility->getItemQty($item, $rule);
        $discountCalculator = $this->calculatorFactory->create($rule->getSimpleAction());
        $qty = $discountCalculator->fixQuantity($qty, $rule);
        $this->eventFix($discountData, $item, $rule, $qty);
        $this->setDiscountBreakdown($discountData, $item, $rule, $address);
        /**
         * We can't use row total here because row total not include tax
         * Discount can be applied on price included tax
         */
        $this->validatorUtility->minFix($discountData, $item, $qty);
        $this->validatorUtility->deltaRoundingFix($discountData, $item);

        /**
         * Apply discount via item
         */
        $this->setDiscountData($discountData, $item);
        $object->maintainAddressCouponCode($address, $rule, $couponCode);
        $object->addDiscountDescription($address, $rule);
        return $this;
    }

    /**
     * Get discount Data
     *
     * @param AbstractItem $item
     * @param \Magento\SalesRule\Model\Rule $rule
     * @return \Magento\SalesRule\Model\Rule\Action\Discount\Data
     */
    protected function getDiscountData($item, $rule)
    {
        $qty = $this->validatorUtility->getItemQty($item, $rule);
        $discountCalculator = $this->calculatorFactory->create($rule->getSimpleAction());
        $qty = $discountCalculator->fixQuantity($qty, $rule);
        $discountData = $discountCalculator->calculate($rule, $item, $qty);

        return $discountData;
    }

    /**
     * Set Discount Breakdown
     *
     * @param \Magento\SalesRule\Model\Rule\Action\Discount\Data $discountData
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem $item
     * @param \Magento\SalesRule\Model\Rule $rule
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return $this
     */
    private function setDiscountBreakdown($discountData, $item, $rule, $address)
    {
        if ($discountData->getAmount() > 0 && $item->getExtensionAttributes()) {
            $data = [
                'amount' => $discountData->getAmount(),
                'base_amount' => $discountData->getBaseAmount(),
                'original_amount' => $discountData->getOriginalAmount(),
                'base_original_amount' => $discountData->getBaseOriginalAmount()
            ];
            $itemDiscount = $this->discountDataInterfaceFactory->create(['data' => $data]);
            $ruleLabel = $rule->getStoreLabel($address->getQuote()->getStore()) ?: __('Discount');
            $data = [
                'discount' => $itemDiscount,
                'rule' => $ruleLabel,
                'rule_id' => $rule->getId(),
            ];
            /** @var \Magento\SalesRule\Model\Data\RuleDiscount $itemDiscount */
            $ruleDiscount = $this->discountInterfaceFactory->create(['data' => $data]);
            $this->discountAggregator[] = $ruleDiscount;
            $item->getExtensionAttributes()->setDiscounts($this->discountAggregator);
        }
        return $this;
    }

    /**
     * Set Discount data
     *
     * @param \Magento\SalesRule\Model\Rule\Action\Discount\Data $discountData
     * @param AbstractItem $item
     * @return $this
     */
    protected function setDiscountData($discountData, $item)
    {
        $item->setDiscountAmount($discountData->getAmount());
        $item->setBaseDiscountAmount($discountData->getBaseAmount());
        $item->setOriginalDiscountAmount($discountData->getOriginalAmount());
        $item->setBaseOriginalDiscountAmount($discountData->getBaseOriginalAmount());

        return $this;
    }

    /**
     * Fire event to allow overwriting of discount amounts
     *
     * @param \Magento\SalesRule\Model\Rule\Action\Discount\Data $discountData
     * @param AbstractItem $item
     * @param Rule $rule
     * @param float $qty
     * @return $this
     */
    protected function eventFix(
        \Magento\SalesRule\Model\Rule\Action\Discount\Data $discountData,
        AbstractItem $item,
        \Magento\SalesRule\Model\Rule $rule,
        $qty
    ) {
        $quote = $item->getQuote();
        $address = $item->getAddress();

        $this->eventManager->dispatch(
            'salesrule_validator_process',
            [
                'rule' => $rule,
                'item' => $item,
                'address' => $address,
                'quote' => $quote,
                'qty' => $qty,
                'result' => $discountData
            ]
        );

        return $this;
    }
}
