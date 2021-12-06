<?php

/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */

class Magic_Checkout_IndexController extends Mage_Core_Controller_Front_Action
{
    // Magic error codes
    public const UNKNOWN_ERROR_BACKEND = 1000;
    public const CREATE_MAGIC_ORDER_ERROR = 1001;
    public const CAPTURE_PAYMENT_ERROR = 1002;
    public const UNKNOWN_ERROR_PLATFROM = 2000;
    public const OUT_OF_STOCK = 2001;
    public const INVALID_REFERENCE = 2002;
    public const NO_SHIPPING_AVAILABLE = 2003;
    public const CREATE_USER_ERROR = 2004;
    public const CREATE_ORDER_ERROR = 2005;
    public const ORDER_VALIDATION_ERROR = 2006;
    public const CREATE_INVOICE_ERROR = 2007;
    public const MISSING_VARIANT_ERROR = 2008;
    public const UNKNOWN_ERROR_GATEWAY = 3000;
    protected $resultPageFactory;
    protected $formKey;
    protected $cart;
    protected $_jsonHelper;
    protected $_quoteRepository;
    protected $_jsonResultFactory;
    protected $_quoteValidator;
    protected $_quoteManagement;
    protected $productFactory;
    protected $_totalsCollector;
    protected $_invoiceService;
    protected $_transaction;
    protected $productRepository;
    protected $_imageHelper;
    protected $_cartHelper;
    protected $_categoryRepository;
    protected $_storeManager;
    protected $formKeyValidator = null;
    protected $_converter;
    protected $logger;
    protected $guestCart;
    protected $cartManagementInterface;
    protected $collectionFactory;
    protected $customerRepository;
    protected $historyRepository;
    protected $orderRepository;
    protected $customerAccountManagement;


    protected $_helper;
    protected $_checkoutSession;


    /**
     * Construct function
     */
    public function _construct()
    {
        parent::_construct();
        $this->_helper = Mage::helper('magic');
        $this->_checkoutSession = Mage::getSingleton('checkout/session');
    }

    /**
     * Execute actions to handle ajax calls from magic wrapper functions
     *
     * @return ResultInterface
     * @throws CouldNotSaveException
     * @throws AlreadyExistsException|LocalizedException
     */
    public function indexAction()
    {
        $this->_helper = Mage::helper('magic');
        $this->_helper->log("Scalapay: Index Action");
        //Adding product to cart, from product page and return cart details


        $action = strtolower($this->getRequest()->getParam('action'));
        switch ($action) {
            case "onexpresscheckout":
                //Adding product to cart, from product page and return cart details
                $result = $this->_onExpressCheckout();
                break;
            case "onexpresscheckoutcart":
                //Returning cart details on cart page
                $result = $this->_onExpressCheckoutCart();
                break;
            case "getshipping":
                //Returning available shipping methods with estimated cost from cart
                try {
                    $result = $this->_getShipingOptions();
                } catch (Exception $e) {
                    $message = $e->getMessage();
                    $this->_helper->log($message);
                }
                break;
            case "getshippingmethodchange":
                //Set selected shipping method in cart and return new total
                $result = $this->_getShipingMethodChange();
                break;
            case "createorder":
                //Creating order in magento and magic order as well,with order amount authorized
                $result = $this->_createOrder();
                break;
            case "completeorder":
                //capturing the order amount and creating magento order invoice
                $result = $this->_completeOrder();
                break;
        }
        return $result;
    }

    /**
     * Create Cart and add product in cart
     *
     * @return Json
     * @throws CouldNotSaveException
     */
    public function _onExpressCheckout()
    {
        $this->_helper->log("=========Controller called In _onExpressCheckout=========");
        if ($this->isAjax()) {
            $currentCart = array();
            $grandTotal = 0;
            $currentCurrencyCode = $this->_helper->getCurrentCurrencyCode();
            //create new parallel cart without interfering existing one
            $cart = Mage::getModel('checkout/cart');
            $cart->init();
            $quote = $cart->getQuote();
            $quote->setIsActive(1)->save();
            $this->_helper->log($quote->getData());
            $quoteId = $quote->getId();

            $this->getCheckoutSession()->setMagicQuoteId($quoteId);
            $this->getCheckoutSession()->setMagicMode('product');

            $currentCart["cartId"] = $quoteId;
            $currentCart["totalAmount"] = array("currency" => "$currentCurrencyCode", "amount" => $grandTotal);
            $currentCart["items"][] = [];

            $itemsData = $this->getRequest()->getParams();
            $items = $itemsData["items"];

            //Add product to cart with current selections
            if ($quoteId) {
                if (count($items) > 0) {
                    foreach ($items as $item) {
                        $super_attribute = "";
                        $itemData = json_decode($item["item"], true);
                        $productId = $itemData["product"];
                        $product = Mage::getModel('catalog/product')->load($productId);
                        if (isset($itemData["super_attribute"])) {
                            $super_attribute = $itemData["super_attribute"];
                        }
                        //use default qty=1 in case we are not getting this from merchnat product page
                        if (isset($itemData["qty"]) && $itemData["qty"] > 0) {
                            $qty = $itemData["qty"];
                        } else {
                            $qty = 1;
                        }

                        $form_key = Mage::getSingleton('core/session')->getFormKey();
                        if ($super_attribute != "") {
                            $params = new Varien_Object([
                                'form_key' => $form_key,
                                'qty' => $qty,
                                'product' => $productId,
                                'super_attribute' => $super_attribute
                            ]);
                        } else {
                            $params = new Varien_Object([
                                'form_key' => $form_key,
                                'qty' => $qty,
                                'product' => $productId
                            ]);
                        }
                        try {
                            $quote->addProduct($product, $params);
                        } catch (LocalizedException $e) {
                            $this->_helper->log($e->getMessage());
                            $response['text'] = $this->_helper->handleErrors(self::OUT_OF_STOCK);
                            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);

                            return $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
                            // return $this->_jsonResultFactory->create()->setData($response);
                        } catch (Exception $e) {
                            $this->_helper->log($e->getMessage());
                            $response['text'] = $this->_helper->handleErrors(self::OUT_OF_STOCK);
                            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);

                            return $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
                        }
                    }
                }
                $cart->save();
                //get cart data
                $cartDetails = $this->getProductCartDetails($quoteId);
                if ($this->getCheckoutSession()->getMagicMode() == "product") {
                    $quote->setIsActive(0)->save();
                }
                $response = ['error' => false, 'message' => $cartDetails];
            //$this->_helper->log($cartDetails);
            } else {
                $response['text'] = $this->_helper->handleErrors(self::INVALID_REFERENCE);
            }
        } else {
            $response['text'] = $this->_helper->handleErrors(self::INVALID_REFERENCE);
        }
        $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
        return $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
    }


    /**
     * Get Cart details
     *
     * @return Json
     */
    public function _onExpressCheckoutCart()
    {
        $this->_helper->log("=========In _onExpressCheckoutCart=========");
        if ($this->isAjax()) {
            //get cart data
            $cartId = $this->getRequest()->getParam('cart_id');
            $this->getCheckoutSession()->unsMagicQuoteId();
            $this->getCheckoutSession()->setMagicMode('cart');
            $this->getCheckoutSession()->setMagicCartId($cartId);
            $cartDetails = $this->getCartDetails($cartId);
            $response = ['error' => false, 'message' => $cartDetails];
        } else {
            $response = $this->_helper->handleErrors(self::INVALID_REFERENCE);
        }
        $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);

        return $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
    }


    /**
     * Get Shipping Options
     *
     * @return Json
     * @throws AlreadyExistsException
     * @throws LocalizedException
     */
    public function _getShipingOptions()
    {
        $this->_helper->log("=========In _getShipingOptions=========");
        $magicPricesInclusiveTax = $this->_helper->getConfigData("magic_prices_inclusive_tax");
        $shippingData = $this->getRequest()->getParams();
        $customerData = $shippingData["shippingAddress"];
        $this->_helper->log("mode: " . $this->getCheckoutSession()->getMagicMode());
        if ($this->getCheckoutSession()->getMagicMode() == "cart") {
            $cartId = $this->getCheckoutSession()->getMagicCartId();
        } elseif ($this->getCheckoutSession()->getMagicMode() == "product") {
            $cartId = $this->getCheckoutSession()->getMagicQuoteId();
        }
        $this->_helper->log("cartId: " . $cartId);
        if (isset($cartId)) {
            $quote = $this->getQuoteById($cartId);
        } else {
            $quote = $this->getCheckoutSession()->getQuote();
        }
        if ($this->getCheckoutSession()->getMagicMode() == "product") {
            $quote->setIsActive(1)->save();
        }
        $this->_helper->log($quote->getData());
        $shippingAddress = $quote->getShippingAddress();
        //setting customer data in quote
        if (!empty($customerData) && !$quote->isVirtual()) {
            // Set first name & lastname in shipping address
            if (isset($customerData["name"]) && $customerData["name"] != "") {
                $fullName = explode(' ', $customerData["name"]);
                $lastName = array_pop($fullName);
                if (count($fullName) == 0) {
                    // if $customerData["name"] contains only one word
                    $firstName = $lastName;
                } else {
                    $firstName = implode(' ', $fullName);
                }
                $shippingAddress->setFirstname($firstName);
                $shippingAddress->setLastname($lastName);
            }
            if (isset($customerData['email']) && $customerData['email'] != "") {
                $shippingAddress->setEmail($customerData['email']);
            }

            $addressLine1 = "";
            $addressLine2 = "";
            if (isset($customerData['line1'])) {
                $addressLine1 = $customerData['line1'];
            }
            if (isset($customerData['line2'])) {
                $addressLine2 = $customerData['line2'];
            }
            $shippingAddress->setStreet(array(
                $addressLine1,
                $addressLine2
            ));
            $shippingAddress->setCountryId($customerData['countryCode']);
            if (isset($customerData['locality'])) {
                $shippingAddress->setCity($customerData['locality']);
            }
            if (isset($customerData['postalCode'])) {
                $shippingAddress->setPostcode($customerData['postalCode']);
            }
            if (isset($customerData['phoneNumber']) && $customerData['phoneNumber'] != "") {
                $shippingAddress->setTelephone($customerData['phoneNumber']);
            }

            if ((isset($customerData['region']) && $customerData['region'] != "") && (isset($customerData['countryCode']) && $customerData['countryCode'] != "")) {
                $region_id = $this->_helper->getRegionId($customerData['region'], $customerData['countryCode']);
                if (isset($region_id) && $region_id > 0) {
                    $shippingAddress->setRegionId($region_id);
                }
                $shippingAddress->setRegion($customerData['region']);
            }
            $shippingAddress->setCollectShippingRates(true);

            try {
                $quote->collectTotals()->save();
            } catch (Exception $e) {
                throw new Exception(__('%1', $e->getMessage()));
            }
        }

        $this->_helper->log("shippingAddress");
        //getting shipping amount on base of customer address for each shipping method
        $output = [];
        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true);

            $quote->collectTotals()->save();
            $rates = array_map(function ($item) {
                return $item->toArray();
            }, $shippingAddress->getAllShippingRates());

            $this->_helper->log("rates");
            $this->_helper->log($rates);
            foreach ($rates as $rate) {
                $output[] = $rate;
            }
        }
        //preparing response
        $shippingData = $output;
        $shippingList = array();
        if (!empty($shippingData)) {
            foreach ($shippingData as $rateData) {
                if ($magicPricesInclusiveTax == true) {
                    $shippingAmount = number_format($rateData['price'], 2, '.', '');
                } else {
                    $shippingAmount = number_format($rateData['price'], 2, '.', '');
                }

                $carrierCode = $rateData['code'];
                $methodCode = $rateData['method'];
                $shippingOptions['name'] = $rateData['carrier_title'];
                $shippingOptions['amount'] = array(
                    'amount' => $this->_helper->toCents($shippingAmount),
                    'currency' => $quote->getStoreCurrencyCode()
                );
                $shippingOptions['token'] = $carrierCode;
                $shippingOptions['isDefault'] = false;
                $shippingList[] = $shippingOptions;
            }
        }
        if (!empty($shippingList)) {
            $shippingData = ['shippingMethods' => $shippingList];
            $response = ['error' => false, 'message' => $shippingData];
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        } elseif ($quote->isVirtual()) {
            $response = $this->_helper->handleErrors(self::NO_SHIPPING_AVAILABLE);
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        } else {
            $response = $this->_helper->handleErrors(self::NO_SHIPPING_AVAILABLE);
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        }
        if ($this->getCheckoutSession()->getMagicMode() == "product") {
            $quote->setIsActive(0)->save();
        }

        return $result;
    }

    /**
     * Get Order total after Shipping Options selection
     *
     * @return Json
     */

    public function _getShipingMethodChange()
    {
        $data = $this->getRequest()->getParams();

        if (isset($data["shippingMethodToken"])) {
            return $this->onShippingMethodChangeDetail($data);
        } else {
            $response = [];
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);

            return $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        }
    }


    /**
     * Set selected shipping method in quote
     *
     * @param $data
     * @return Json
     */
    public function onShippingMethodChangeDetail($data)
    {
        $this->_helper->log("=========In onShippingMethodChangeDetail=========");
        try {
            $magicPricesInclusiveTax = $this->_helper->getConfigData("magic_prices_inclusive_tax");
            $shippingMethodSelected = $data["shippingMethodToken"];
            $this->_helper->log("shippingMethodSelected:" . $shippingMethodSelected);
            if ($this->getCheckoutSession()->getMagicMode() == "cart") {
                $cartId = $this->getCheckoutSession()->getMagicCartId();
            } elseif ($this->getCheckoutSession()->getMagicMode() == "product") {
                $cartId = $this->getCheckoutSession()->getMagicQuoteId();
            }
            if (isset($cartId)) {
                $quote = $this->getQuoteById($cartId);
            } else {
                $quote = $this->getCheckoutSession()->getQuote();
            }
            if ($this->getCheckoutSession()->getMagicMode() == "product") {
                $quote->setIsActive(1)->save();
            }
            if (isset($shippingMethodSelected) && $shippingMethodSelected != "") {
                $shippingAddress = $quote->getShippingAddress();
                //setting selected shipping method to get new totals
                $shippingAddress->setShippingMethod($shippingMethodSelected);
                $quote->collectTotals()->save();
                //$this->_quoteRepository->save($quote);
            }
            $grandTotal = $quote->getGrandTotal();


            if ($magicPricesInclusiveTax == true) {
                $shippingAmount = $quote->getShippingAddress()->getShippingInclTax();
            } else {
                $shippingAmount = $quote->getShippingAddress()->getShippingAmount();
            }

            $updateTotals['orderAmount'] = array(
                'amount' => $this->_helper->toCents($grandTotal),
                'currency' => $quote->getBaseCurrencyCode()
            );

            $subtotalAmount = $quote->getSubtotal();
            $discountAmount = $quote->getSubtotal() - $quote->getSubtotalWithDiscount();
            $taxAmount = $quote->getShippingAddress()->getTaxAmount();
            if ($taxAmount == "") {
                $taxAmount = 0;
            }
            if ($magicPricesInclusiveTax == true) {
                $subtotalAmount = 0;
                $subtotalAmount = $quote->getShippingAddress()->getSubtotalInclTax();
                if ($subtotalAmount == 0) {
                    $subtotalAmount = $quote->getShippingAddress()->getSubtotal();
                    $shippingTaxAmount = $quote->getShippingAddress()->getShippingTaxAmount();
                    $subtotalAmount = $subtotalAmount + ($taxAmount - $shippingTaxAmount);
                }
            } else {
                if ($taxAmount > 0) {
                    $updateTotals['orderAmountDetails']['taxAmount'] = array(
                        'amount' => $this->_helper->toCents($taxAmount),
                        'currency' => $quote->getBaseCurrencyCode()
                    );
                }
            }

            if ($discountAmount == 0 && abs($quote->getShippingAddress()->getDiscountAmount()) > 0) {
                $discountAmount = abs($quote->getShippingAddress()->getDiscountAmount());
            }
            $updateTotals['orderAmountDetails']['shippingAmount'] = array(
                'amount' => $this->_helper->toCents($shippingAmount),
                'currency' => $quote->getBaseCurrencyCode()
            );

            if ($discountAmount > 0) {
                $updateTotals['orderAmountDetails']['discountAmount'] = array(
                    'amount' => $this->_helper->toCents($discountAmount),
                    'currency' => $quote->getBaseCurrencyCode()
                );
            }
            $updateTotals['orderAmountDetails']['itemSubtotalAmount'] = array(
                'amount' => $this->_helper->toCents($subtotalAmount),
                'currency' => $quote->getBaseCurrencyCode()
            );
            $response = ['error' => false, 'message' => $updateTotals];
            $this->_helper->log($response);
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        } catch (Exception $e) {
            $this->_helper->log($e->getMessage());
            $response = $this->_helper->handleErrors(self::NO_SHIPPING_AVAILABLE);
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        }
        if ($this->getCheckoutSession()->getMagicMode() == "product") {
            $quote->setIsActive(0)->save();
        }

        return $result;
    }


    /**
     * Create Order in magento and Magic
     * @return Json
     */
    public function _createOrder()
    {
        $this->_helper->log("=========In _createOrder=========");
        try {
            $orderToken = "";
            //getting Magic posted data
            $orderData = $this->getRequest()->getParams();
            $this->_helper->log($orderData);
            //get current quote
            if ($this->getCheckoutSession()->getMagicMode() == "cart") {
                $cartId = $this->getCheckoutSession()->getMagicCartId();
            } elseif ($this->getCheckoutSession()->getMagicMode() == "product") {
                $cartId = $this->getCheckoutSession()->getMagicQuoteId();
            }
            if (isset($cartId)) {
                $quote = $this->getQuoteById($cartId);
            } else {
                $quote = $this->getCheckoutSession()->getQuote();
            }
            if ($this->getCheckoutSession()->getMagicMode() == "product") {
                $quote->setIsActive(1)->save();
            }

            //Setting selected shipping method in quote at last moment to make sure we have correct one
            if (isset($orderData["OrderRequest"]["shipping"]["shippingMethod"]["token"]) && isset($orderData["OrderRequest"]["shipping"]["shippingMethod"]["token"]) != "") {
                $this->setShippingMethodInCreateOrder($quote, $orderData["OrderRequest"]["shipping"]["shippingMethod"]["token"]);
            }

            $customerEmail = (isset($orderData["OrderRequest"]["email"])) ? $orderData["OrderRequest"]["email"] : "";
            //load existing customer or treat as guest user and also check if customer has default billing address or not
            $defaultBillingAddressId = $this->prepareCustomer($quote, $customerEmail);

            //updating quote shipping/billing address with magic data
            $this->updateCustomerAddressWithMagicData($orderData, $quote, $defaultBillingAddressId);

            //reserve order ID
            $quote->reserveOrderId();
            $quote->save();
            $merchantReferenceQuoteId = $quote->getReservedOrderId();

            $this->_helper->log("merchantReferenceQuoteId: " . $merchantReferenceQuoteId);

            $this->_helper->log("shippingNotes: " . $$orderData["OrderRequest"]["shipping"]["address"]["notes"]);

            $this->_helper->log("billingNotes: " . $orderData["OrderRequest"]["billing"]["address"]["notes"]);

            //merchant order created successfully
            if ($merchantReferenceQuoteId) {
                //get order notes
                $shippingNotes = (isset($orderData["OrderRequest"]["shipping"]["address"]["notes"])) ? $orderData["OrderRequest"]["shipping"]["address"]["notes"] : "shi[pping notes are here";
                $billingNotes = (isset($orderData["OrderRequest"]["billing"]["address"]["notes"])) ? $orderData["OrderRequest"]["billing"]["address"]["notes"] : "billinging notes are here";
                //save order notes in session for later update in order
                $this->getCheckoutSession()->setShippingNotes($shippingNotes);
                $this->getCheckoutSession()->setBillingNotes($billingNotes);


                //create Magic order
                $this->_helper->log("Before Magic order");
                $this->_helper->log("getQuote: ID Before Magic order" . $quote->getId());
                $orderData = $this->createMagicOrder($quote, $orderData);
                $this->_helper->log("getQuote: ID after Magic order" . $quote->getId());
                $this->_helper->log("After Magic order");
                $this->_helper->log($orderData);

                if ($orderData["magicOrderToken"] == "") {
                    $this->getCheckoutSession()->unsMagicCartId();
                    $this->getCheckoutSession()->unsMagicQuoteId();
                    $response = $this->_helper->handleErrors(self::CREATE_MAGIC_ORDER_ERROR);
                } else {
                    $this->_helper->log("Magic order Created Successfully");
                    $response = ['error' => false, 'message' => $orderData];
                }

                $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
                $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
            } else {
                $this->getCheckoutSession()->unsMagicCartId();
                $this->getCheckoutSession()->unsMagicQuoteId();
                $this->getCheckoutSession()->unsMagicCartId();
                $response = $this->_helper->handleErrors(self::CREATE_ORDER_ERROR);
                $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
                $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
                $this->_helper->log("Order Exception : There was a problem with order creation.");
            }
        } catch (LocalizedException $e) {
            $this->getCheckoutSession()->unsMagicCartId();
            $this->getCheckoutSession()->unsMagicQuoteId();
            $response = $this->_helper->handleErrors(self::CREATE_ORDER_ERROR);
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
            $this->_helper->log("_confirm : Transaction LocalizedException: " . $e->getMessage());
        } catch (Exception $e) {
            $this->getCheckoutSession()->unsMagicCartId();
            $this->getCheckoutSession()->unsMagicQuoteId();
            $response = $this->_helper->handleErrors(self::CREATE_ORDER_ERROR);
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
            $this->_helper->log("_confirm : Transaction Exception: " . $e->getMessage());
        }
        if ($this->getCheckoutSession()->getMagicMode() == "product") {
            $quote->setIsActive(0)->save();
        }

        return $result;
    }


    /**
     * Update customer address with magic provide data
     *
     */
    public function updateCustomerAddressWithMagicData($orderData, $quote, $defaultBillingAddressId)
    {
        $shippingAddress = $quote->getShippingAddress();
        $this->updateCustomerShippingAddress($orderData, $shippingAddress);

        $isGuestCheckout = $quote->getCustomerIsGuest();
        //update billing address if guest customer or there is no default billing address of registered customer (Because billing is required for order)
        if ($isGuestCheckout == true || $defaultBillingAddressId == 0) {
            $billingAddress = $quote->getBillingAddress();
            $this->updateCustomerBillingAddress($shippingAddress, $billingAddress);
        }
    }


    /**
     * Update customer shipping address with magic provide data
     *
     */
    public function updateCustomerShippingAddress($orderData, $shippingAddress)
    {
        $this->_helper->log("=========In updateCustomerShippingAddress =========");
        //$this->_helper->log("=========orderData =========");
        //$this->_helper->log($orderData);
        $firstName = (isset($orderData["OrderRequest"]["shipping"]["name"]["firstName"])) ? $orderData["OrderRequest"]["shipping"]["name"]["firstName"] : "";
        $lastName = (isset($orderData["OrderRequest"]["shipping"]["name"]["lastName"])) ? $orderData["OrderRequest"]["shipping"]["name"]["lastName"] : "";
        $customerPhoneCode = (isset($orderData["OrderRequest"]["phoneNumber"]["countryCode"])) ? $orderData["OrderRequest"]["phoneNumber"]["countryCode"] : "";
        $customerPhoneNumber = (isset($orderData["OrderRequest"]["phoneNumber"]["number"])) ? $orderData["OrderRequest"]["phoneNumber"]["number"] : "";
        $addressLine1 = (isset($orderData["OrderRequest"]["shipping"]["address"]["line1"])) ? $orderData["OrderRequest"]["shipping"]["address"]["line1"] : "";
        $addressLine2 = (isset($orderData["OrderRequest"]["shipping"]["address"]["line2"])) ? $orderData["OrderRequest"]["shipping"]["address"]["line2"] : "";
        $customerCountryCode = (isset($orderData["OrderRequest"]["shipping"]["address"]["countryCode"])) ? $orderData["OrderRequest"]["shipping"]["address"]["countryCode"] : "";
        if ($customerCountryCode != "") {
            $shippingAddress->setCountryId($customerCountryCode);
        }
        $customerpostalCode = (isset($orderData["OrderRequest"]["shipping"]["address"]["postalCode"])) ? $orderData["OrderRequest"]["shipping"]["address"]["postalCode"] : "";
        if ($customerpostalCode != "") {
            $shippingAddress->setPostcode($customerpostalCode);
        }
        $customerlocality = (isset($orderData["OrderRequest"]["shipping"]["address"]["locality"])) ? $orderData["OrderRequest"]["shipping"]["address"]["locality"] : "";
        if ($customerlocality != "") {
            $shippingAddress->setCity($customerlocality);
        }
        $region_id = 0;
        $region = (isset($orderData["OrderRequest"]["billing"]["address"]["region"])) ? $orderData["OrderRequest"]["billing"]["address"]["region"] : "";
        if ($region != "") {
            if (isset($customerCountryCode) && $customerCountryCode != "") {
                $region_id = $this->_helper->getRegionId($region, $customerCountryCode);
                if (isset($region_id) && $region_id > 0) {
                    $shippingAddress->setRegionId($region_id);
                }
            }
            $shippingAddress->setRegion($region);
        }

        if ($customerPhoneNumber != "") {
            $shippingAddress->setTelephone($customerPhoneCode . $customerPhoneNumber);
        }
        if ($firstName != "") {
            $shippingAddress->setFirstname($firstName);
        }
        if ($lastName != "") {
            $shippingAddress->setLastname($lastName);
        }
        if ("" != $addressLine1 && "" != $addressLine2) {
            $shippingAddress->setStreet(array(
                $addressLine1,
                $addressLine2
            ));
        } else {
            if ($addressLine1 != "") {
                $shippingAddress->setStreet(array(
                    $addressLine1
                ));
            }
        }
        $shippingAddress->save();
        $this->_helper->log("Shipping saved");
    }

    /**
     * Update customer billing address ,same as shipping
     *
     */
    public function updateCustomerBillingAddress($shippingAddress, $billingAddress)
    {
        $this->_helper->log("=========In updateCustomerBillingAddress =========");
        $firstName = $shippingAddress->getFirstname();
        $lastName = $shippingAddress->getLastname();
        $countryCode = $shippingAddress->getCountryId();
        $suburb = $shippingAddress->getCity();
        $postcode = $shippingAddress->getPostcode();
        $phoneNumber = $shippingAddress->getTelephone();
        $billingAddress->setFirstname($firstName);
        $billingAddress->setLastname($lastName);
        $customerStreet = $shippingAddress->getStreet();
        $addressLine1 = (isset($customerStreet[0])) ? $customerStreet[0] : "";
        $addressLine2 = (isset($customerStreet[1])) ? $customerStreet[1] : "";
        $region = $shippingAddress->getRegion();
        $region_id = $shippingAddress->getRegionId();

        if ($addressLine1 != "" && $addressLine2 != "") {
            $billingAddress->setStreet(array(
                $addressLine1,
                $addressLine2
            ));
        } else {
            if ($addressLine1 != "") {
                $billingAddress->setStreet(array(
                    $addressLine1
                ));
            }
        }
        $billingAddress->setCountryId($countryCode);
        $billingAddress->setCity($suburb);
        $billingAddress->setPostcode($postcode);
        $billingAddress->setTelephone($phoneNumber);

        if ($region != "") {
            $billingAddress->setRegion($region);
        }
        if (isset($region_id) && $region_id > 0) {
            $billingAddress->setRegionId($region_id);
        }
        $billingAddress->save();
        $this->_helper->log("Billing saved");
    }


    /**
     * Create Magento order from quote data
     *
     * @return object
     *
     */
    public function createMerchantOrder($quote, $orderId)
    {
        $payment = $quote->getPayment();
        $payment->setMethod('magic');
        $quote->setReservedOrderId($orderId);
        $quote->setPayment($payment);
        $quote->collectTotals();
        $quote->save();
        $this->getCheckoutSession()->getQuote()->setLastQuoteId($quote->getId())
            ->setLastSuccessQuoteId($quote->getId());
        $this->getCheckoutSession()->clearHelperData();
        $quote->collectTotals();

        $billingAddressValidation = $quote->getBillingAddress()->validate();
        // Catch the deadlock exception while creating the order and retry 3 times
        $order = '';
        $tries = 0;
        // Create Order From Quote
        do {
            $retry = false;
            try {
                // Create order in Magento with 3 tries
                $this->_helper->log("Trying Order Creation. Try number:" . $tries);
                ///$order = $this->_quoteManagement->submit($quote);
                $service = Mage::getModel('sales/service_quote', $quote);
                $service->submitAll();
                $order = $service->getOrder();
            } catch (LocalizedException $e) {
                $this->_helper->log($e->getMessage());
                $this->getCheckoutSession()->unsMagicQuoteId();
                $response = $this->_helper->handleErrors(self::CREATE_ORDER_ERROR);
                return $this->_jsonResultFactory->create()->setData($response);
            } catch (Exception $e) {
                if (
                    preg_match(
                        '/SQLSTATE\[40001]: Serialization failure: 1213 Deadlock found/',
                        $e->getMessage()
                    ) && $tries < 2
                ) {
                    $this->_helper->log("Waiting for a second before retrying the Order Creation");
                    $retry = true;
                    sleep(1);
                } else {
                    // Reverse or void the order
                    $this->_helper->log("Reverse or void the order");
                    $this->_helper->log($e->getMessage());
                    $this->getCheckoutSession()->unsMagicQuoteId();
                    $response = $this->_helper->handleErrors(self::CREATE_ORDER_ERROR);

                    $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
                    return $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
                }
            }
            $tries++;
        } while ($tries < 3 && $retry);

        return $order;
    }

    /**
     * Add billing/shipping notes in merchant order
     *
     */
    public function updateOrderNotes($order, $shippingNotes, $billingNotes)
    {
        if ($order->canComment()) {
            if ($shippingNotes != "") {
                $order->addStatusToHistory($order->getStatus(), $this->_helper->___('Shipping Notes: %s.', $shippingNotes), false);
            }
            if ($billingNotes != "") {
                $order->addStatusToHistory($order->getStatus(), $this->_helper->__('Billing Notes: %s.', $billingNotes), false);
            }
        }
    }

    /**
     * Create Magic order from magento order data
     *
     * @return array
     *
     */
    public function createMagicOrder($quote, $orderData): array
    {
        $orderToken = "";
        $merchantReferenceQuoteId = $quote->getReservedOrderId();
        if ($merchantReferenceQuoteId == "") {
            $merchantReferenceQuoteId = (int)$this->getCheckoutSession()->getQuote()->getId();
        }
        $merchantReferenceId = $quote->getReservedOrderId();
        if ($merchantReferenceId == "") {
            $merchantReferenceId = $merchantReferenceQuoteId;
        }
        $this->_helper->log("Magento order created successfully");
        //get magic orderToken
        $res = $this->_helper->createMagicOrder($quote, $merchantReferenceId, $orderData);
        if (isset($res["error"]) && $res["error"] == 1) {
            $this->getCheckoutSession()->unsMagicQuoteId();
            $response = $this->_helper->handleErrors(self::CREATE_MAGIC_ORDER_ERROR);
            return $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        } elseif (isset($res["error"]) && $res["error"] == 0) {
            if (isset($res["message"])) {
                $orderToken = $res["message"];
                $this->_helper->log("Magento order created successfully");
            }
        }

        $orderData = ['orderId' => $merchantReferenceId, 'magicOrderToken' => $orderToken];

        return $orderData;
    }

    /**
     * Set selected shipping method in createOrder function
     *
     * @param $shippingMethodSelected
     */
    public function setShippingMethodInCreateOrder($quote, $shippingMethodSelected)
    {
        $this->_helper->log("================= setShippingMethodInCreateOrder=========");

        try {
            // setting shipping method while creating order in magento
            $this->_helper->log("shippingMethodSelected:" . $shippingMethodSelected);
            $shippingAddress = $quote->getShippingAddress();
            if (isset($shippingMethodSelected) && $shippingMethodSelected != "") {
                $shippingAddress->setShippingMethod($shippingMethodSelected);
            }
            $quote->collectTotals()->save();
        } catch (Exception $e) {
            $this->_helper->log($e->getMessage());
        }
    }


    /**
     * Use existing customer for order if exist on base of email search, otherwise treat as guest user
     *
     * @return int
     *
     */
    public function prepareCustomer($quote, $customerEmail)
    {
        $defaultBillingAddressId = 0;
        $websiteId = Mage::app()->getWebsite()->getId();
        $shippingAddress = $quote->getShippingAddress();
        // instantiate customer object
        $customer = Mage::getModel('customer/customer')
            ->setWebsiteId($websiteId)
            ->loadByEmail($customerEmail);
        // check if customer is already present
        // if customer is already present, then update address
        // else create new customer
        $this->_helper->log($customerEmail);
        $this->_helper->log("after load" . $customerId);
        if ($customer->getId()) {
            $customerId = $customer->getId();
            //$customer = $this->customerRepository->getById($customerId);
            $this->_helper->log("customerId" . $customerId);
            //$customer = $this->customerRepository->getById($customerId);
            $quote->assignCustomer($customer); //Assign Quote to Customer
            $defaultBillingAddress = $customer->getDefaultBilling();
            if (isset($defaultBillingAddress) && $defaultBillingAddress > 0) {
                $defaultBillingAddressId = $defaultBillingAddress;
            }
            $this->_helper->log("defaultBillingAddressId: " . $defaultBillingAddressId);
        } else {
            $quote->setCustomerIsGuest(true)->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);
        }
        // Restore Customer email address if it becomes null/blank
        if (empty($quote->getCustomerEmail())) {
            $this->_helper->log("customerEmail: " . $customerEmail);
            $quote->setCustomerEmail($customerEmail);
        }
        //$quote->save();
        return $defaultBillingAddressId;
    }

    /**
     * Get product cart details
     *
     * @param $mode
     * @return array
     */
    public function getProductCartDetails($quoteId)
    {
        $currentCart = array();
        $currentCurrencyCode = $this->_helper->getCurrentCurrencyCode();
        $this->_helper->log("getProductCartDetails - quoteId: " . $quoteId);
        $quote = $this->getQuoteById($quoteId);
        //recalculate totals
        $quote->collectTotals();
        $shippingAmount = $quote->getShippingAddress()->getShippingAmount();
        $grandTotal = $quote->getGrandTotal();
        $grandTotal = $grandTotal - $shippingAmount;
        $currentCart["id"] = $quote->getId();
        $currentCart["orderAmount"] = array(
            "currency" => "$currentCurrencyCode",
            "amount" => $this->_helper->toCents($grandTotal)
        );
        $discountAmount = $quote->getSubtotal() - $quote->getSubtotalWithDiscount();
        if ($discountAmount > 0) {
            $currentCart["orderAmountDetails"]["discountAmount"] = array(
                "currency" => "$currentCurrencyCode",
                "amount" => "$discountAmount"
            );
        }
        // get quote items array
        $items = $quote->getAllItems();
        foreach ($items as $item) {
            if ($item->getParentItemId()) {
                continue;
            }
            $itemsList = $this->getCartItemDetails($item);
            $currentCart["items"][] = $itemsList;
        }
        $this->_helper->log("currentCart: ");
        $this->_helper->log($currentCart);
        return $currentCart;
    }

    /**
     * Get cart item details
     *
     * @param object $item
     * @return array
     */
    public function getCartItemDetails($item)
    {
        $productId = $item->getProductId();
        $itemsList = [];
        $magicPricesInclusiveTax = $this->_helper->getConfigData("magic_prices_inclusive_tax");
        try {
            $_product = Mage::getModel('catalog/product')->load($productId);
            $currentCurrencyCode = $this->_helper->getCurrentCurrencyCode();
            $itemsList["id"] = $productId;
            $itemsList["name"] = $item->getName();
            $itemsList["quantity"] = $item->getQty();
            if ($magicPricesInclusiveTax == true) {
                $itemsList["unitPrice"] = array(
                    "currency" => "$currentCurrencyCode",
                    "amount" => $this->_helper->toCents($item->getPriceInclTax())
                );
                $itemsList["totalAmount"] = array(
                    "currency" => "$currentCurrencyCode",
                    "amount" => $this->_helper->toCents($item->getRowTotalInclTax())
                );
            } else {
                $itemsList["unitPrice"] = array(
                    "currency" => "$currentCurrencyCode",
                    "amount" => $this->_helper->toCents($item->getPrice())
                );
                $itemsList["totalAmount"] = array(
                    "currency" => "$currentCurrencyCode",
                    "amount" => $this->_helper->toCents($item->getRowTotal())
                );
            }

            $customOptions = $item->getOptions();
            //getting product custom options
            if (isset($customOptions) && !empty($customOptions)) {
                $customOption = array();
                foreach ($customOptions as $option) {
                    $optionValue = $option->getData('value');
                    $optionCode = $option->getData('code');
                    if (isset($optionValue) && $optionValue != "" && is_string($optionValue) && $optionCode == "info_buyRequest") {
                        $optionValueData = unserialize($optionValue);
                        if (isset($optionValueData['super_attribute'])) {
                            //info_buyRequest
                            $superAttribute = $optionValueData['super_attribute'];
                            foreach ($superAttribute as $key => $value) {
                                $attr = Mage::getModel('eav/entity_attribute')->load($key);
                                $attr->getStoreLabel($storeId);
                                $attributeCode = $attr->getAttributeCode();
                                $optionId = $value;
                                $attribute = Mage::getSingleton('eav/config')
                                    ->getAttribute(Mage_Catalog_Model_Product::ENTITY, $attributeCode);
                                $optionText = $attribute->getSource()->getOptionText($optionId);
                                $customOption[] = array($attributeCode => $optionText);
                            }
                        }
                    }
                }
                $itemsList["variant"] = $customOption;
            }

            $imageUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'catalog/product' . $_product->getImage();
            $itemsList["productUrl"] = $_product->getProductUrl();
            $itemsList["imageUrl"] = $imageUrl;

            $brand = $_product->getData("manufacturer");
            if ($brand != "") {
                $itemsList["brand"] = $brand;
            }
            //getting categories of product
            $categoryName = "";
            $categoryIds = $_product->getCategoryIds();
            if (count($categoryIds) > 0) {
                foreach ($categoryIds as $c) {
                    if ($c > 2) {
                        try {
                            //$category = $this->_categoryRepository->get($c, $this->_storeManager->getStore()->getId());
                            $category = Mage::getModel('catalog/category')->load($c);
                        } catch (Exception $e) {
                            $this->_helper->log($e->getMessage());
                            continue;
                        }
                        if ($category) {
                            if ($category->getLevel() == 2) {
                                $categoryName = $category->getName();
                            } else {
                                if ($category->getLevel() >= 3) {
                                    $subCategory[] = $category->getName();
                                }
                            }
                            if ($categoryName == "") {
                                $categoryName = $category->getName();
                            }
                        }
                    }
                }
            }
            $itemsList["category"] = $categoryName;
        } catch (Exception $e) {
        }
        return $itemsList;
    }

    /**
     * Get cart details
     *
     * @param $mode
     * @return array
     */
    public function getCartDetails($cartId = null)
    {
        $currentCart = array();
        $currentCurrencyCode = $this->_helper->getCurrentCurrencyCode();
        if ($cartId == null) {
            $quote = $this->getCheckoutSession()->getQuote();
        } else {
            $quote = $this->getQuoteById($cartId);
        }

        $this->_helper->log("_helper->getCurrentCart -quote: ");
        $getSummaryCount = $quote->getItemsSummaryQty();
        $this->_helper->log("carthelper-getitemcount: " . $getSummaryCount);
        if ($quote->getItemsSummaryQty() > 0) {
            // get quote items collection
            // get array of all items what can be display directly

            $this->_helper->log("carthelper-getitemcount: " . $quote->getShippingAddress()->getShippingAmount());
            $shippingAmount = $quote->getShippingAddress()->getShippingAmount();
            $grandTotal = $quote->getGrandTotal();
            $grandTotal = $grandTotal - $shippingAmount;
            $currentCart["id"] = $quote->getId();
            $currentCart["orderAmount"] = array(
                "currency" => "$currentCurrencyCode",
                "amount" => $this->_helper->toCents($grandTotal)
            );
            $discountAmount = $quote->getSubtotal() - $quote->getSubtotalWithDiscount();
            if ($discountAmount > 0) {
                $currentCart["orderAmountDetails"]["discountAmount"] = array(
                    "currency" => "$currentCurrencyCode",
                    "amount" => "$discountAmount"
                );
            }
            // get quote items array
            $items = $quote->getAllItems();
            foreach ($items as $item) {
                if ($item->getParentItemId()) {
                    continue;
                }
                $itemsList = $this->getCartItemDetails($item);
                $currentCart["items"][] = $itemsList;
            }
        }
        return $currentCart;
    }


    /**
     * Capture payment and create invoice for magento order
     *
     * @return Json
     */
    public function _completeOrder()
    {
        $this->_helper->log("_completeOrder start");

        $result = "";
        try {
            //validating order information coming from magic with magento order
            $orderData = $this->getRequest()->getParams();
            $this->_helper->log($orderData);
            $orderToken = $orderData["orderToken"];
            $this->_helper->log("Magic order token:" . $orderToken);
            //getting magic order information using magic order token
            $payload = $this->_helper->getMagicOrderData($orderToken);
            $this->_helper->log("payload: ");
            $this->_helper->log($payload);
            $orderId = $orderData["merchantReference"];
            $order_id_return = $orderId;
            $this->_helper->log("Magic magento returned order Id:" . $orderId);
            if ($this->getCheckoutSession()->getMagicMode() == "cart") {
                $quoteId = $this->getCheckoutSession()->getMagicCartId();
            } elseif ($this->getCheckoutSession()->getMagicMode() == "product") {
                $quoteId = $this->getCheckoutSession()->getMagicQuoteId();
            }
            if (isset($quoteId)) {
                $quote = $this->getQuoteById($quoteId);
            } else {
                $quote = $this->getCheckoutSession()->getQuote();
            }
            if ($this->getCheckoutSession()->getMagicMode() == "product") {
                $quote->setIsActive(1)->save();
            }
            $this->_helper->log("Magic magento returned quote Id:" . $quoteId);
            $order_total_return = $payload['totalAmount'];
            $this->_helper->log("Magic totalAmount:" . $order_total_return);
            $grandTotal = $quote->getGrandTotal();
            $grandTotal = $this->_helper->toCents($grandTotal);
            $this->_helper->log("Magento grandTotal:" . $grandTotal);
            $proceed_for_order = true;
            //extra check if user has the same cart details for which he/she paid the amount
            if ($order_id_return == "" || $order_total_return == 0) {
                $proceed_for_order = false;
                $this->_helper->log("Proceed_for_order: No 1");
            }
            //double checking if, this is same order and order total amount that we authorized
            if ($order_id_return != "" && $order_total_return > 0) {
                if (
                    $order_id_return != $orderId ||
                    !$this->_helper->epsilonCalculation($grandTotal, $order_total_return)
                ) {
                    $proceed_for_order = false;
                    $this->_helper->log("Proceed_for_order: No 2");
                }
            }
            if (isset($payload['status'])) {
                $status = $payload['status'];
            }
            //before amount capture, checking of amount is already authorized or not
            if (isset($status) && $status == "authorized" && isset($orderToken) && $proceed_for_order) {
                //create merchant order
                $order = $this->createMerchantOrder($quote, $orderId);
                $orderId = $order->getId();
                //merchant order created successfully
                if ($orderId) {
                    //adding notes in order
                    $shippingNotes = $this->getCheckoutSession()->getShippingNotes();
                    $billingNotes = $this->getCheckoutSession()->getBillingNotes();
                    //update order notes
                    //$this->updateOrderNotes($order, $shippingNotes, $billingNotes);
                    $this->_helper->log("shippingNotes: " . $shippingNotes);
                    $this->_helper->log("billingNotes: " . $billingNotes);
                    $this->_helper->log("Can Invoice: before");
                    if ($order->canInvoice()) {
                        $this->_helper->log("Magic Order Capture before.");
                        $captureResponse = $this->_helper->magicOrderCapture(
                            $orderToken,
                            $order->getIncrementId()
                        );
                        if (!$captureResponse["error"]) {
                            $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                            $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                            $invoice->register();
                            $transactionSave = Mage::getModel('core/resource_transaction')
                                ->addObject($invoice)
                                ->addObject($invoice->getOrder());

                            $invoice->setTransactionTd($orderToken);
                            $invoice->save();
                            $transactionSave->save();
                            $this->_helper->log("Invoice Saved");
                            $this->_helper->log("Invoice Created");
                            //send notification code

                            $historyItem = Mage::getModel('sales/order_status_history')
                                ->setOrder($order)
                                ->setStatus($order->getStatus())
                                ->setIsCustomerNotified(true)
                                ->setComment('Payment reference order token ' . $orderToken)
                                ->setData('entity_name', Mage_Sales_Model_Order::HISTORY_ENTITY_NAME);

                            $historyItem->save();
                            $order->addStatusHistory($historyItem);

                            if ($order->canComment()) {
                                if ($shippingNotes != "") {
                                    $shippingHistoryItem = Mage::getModel('sales/order_status_history')
                                        ->setOrder($order)
                                        ->setStatus($order->getStatus())
                                        ->setIsCustomerNotified(true)
                                        ->setComment($this->_helper->__('Shipping Notes: %s.', $shippingNotes))
                                        ->setData('entity_name', Mage_Sales_Model_Order::HISTORY_ENTITY_NAME);
                                    $shippingHistoryItem->save();
                                    $order->addStatusHistory($shippingHistoryItem);
                                }
                                if ($billingNotes != "") {
                                    $billingHistoryItem = Mage::getModel('sales/order_status_history')
                                        ->setOrder($order)
                                        ->setStatus($order->getStatus())
                                        ->setIsCustomerNotified(true)
                                        ->setComment($this->_helper->__('Billing Notes: %s.', $billingNotes))
                                        ->setData('entity_name', Mage_Sales_Model_Order::HISTORY_ENTITY_NAME);

                                    $billingHistoryItem->save();
                                    $order->addStatusHistory($billingHistoryItem);
                                }
                            }
                            $order->setMagicOrderToken($orderToken);
                            $order->save();
                            $this->_helper->log("Order Created");
                            $this->_helper->log("Order IncreamentId: " . $order->getIncrementId());
                            $this->getCheckoutSession()->setLastOrderId($order->getId());
                            $this->_helper->log("LastOrderId: " . $order->getIncrementId());
                            $this->getCheckoutSession()->setLastSuccessQuoteId($order->getQuoteId());

                            $this->getCustomerSession()->setLastSuccessQuoteId($order->getQuoteId());
                            $this->getCustomerSession()->setLastPaymentMethod("magic");
                            $this->_helper->log("LastSuccessQuoteId: " . $order->getQuoteId());
                            $this->getCheckoutSession()->setLastQuoteId($order->getQuoteId());
                            $this->_helper->log("LastQuoteId: " . $order->getQuoteId());

                            $this->getCheckoutSession()->setLastRealOrderId($order->getIncrementId());
                            $this->getCheckoutSession()->setLastOrderStatus($order->getStatus());
                            $this->getCheckoutSession()->unsMagicCartId();
                            $this->getCheckoutSession()->unsMagicQuoteId();

                            //redirect user to success page
                            $redirectConfirmUrl = Mage::getUrl('checkout/onepage/success');
                            $successData = array(
                                'success' => true,
                                'redirectConfirmUrl' => $redirectConfirmUrl
                            );
                            $this->_helper->log("redirectConfirmUrl: " . $redirectConfirmUrl);
                            $response = ['error' => false, 'message' => $successData];
                            $this->_helper->log($response);
                            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
                        } else {
                            $this->getCheckoutSession()->unsMagicCartId();
                            $this->getCheckoutSession()->unsMagicQuoteId();
                            $message = "Something went wrong";
                            if (isset($captureResponse["message"]) && $captureResponse["message"] != "") {
                                $message = $captureResponse["message"];
                            }
                            $response = $this->_helper->handleErrors(self::CAPTURE_PAYMENT_ERROR);
                        }
                        $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
                    }
                } else {
                    $response = $this->_helper->handleErrors(self::CREATE_ORDER_ERROR);
                    $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
                }
            } else {
                $response = $this->_helper->handleErrors(self::ORDER_VALIDATION_ERROR);
                $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
                $this->_helper->log("Order Exception : There was a problem with order creation.");
            }
        } catch (Exception $e) {
            $response = $this->_helper->handleErrors(self::ORDER_VALIDATION_ERROR);
            $result = $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        }

        $this->getCheckoutSession()->unsMagicCartId();
        $this->getCheckoutSession()->unsMagicQuoteId();
        $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);

        if ($this->getCheckoutSession()->getMagicMode() == "product") {
            $quote->setIsActive(0)->save();
        }
        return $result;
    }


    /*
     *  Check Request is Ajax or not
     * @return boolean
     * */
    public function isAjax()
    {
        return (bool)$this->getRequest()->isAjax();
    }

    /**
     * Get Magento Quote by quoteId from session/magento
     *
     * @return object
     *
     */
    public function getQuote()
    {
        $quote = null;
        if ($this->getCheckoutSession()->getMagicMode() == "product") {
            $quoteId = $this->getCheckoutSession()->getMagicQuoteId();
            if (isset($quoteId) && $quoteId > 0) {
                $this->_helper->log("magicQuoteId: " . $quoteId);
                $quote = $this->getQuoteById($quoteId);
            }
        } elseif ($this->getCheckoutSession()->getMagicMode() == "cart") {
            $quoteId = $this->getCheckoutSession()->getCartQuoteId();
            $quote = $this->getCheckoutSession()->getQuote();
        }

        if (isset($quoteId) && $quoteId > 0) {
            $this->_helper->log("magicQuoteId: " . $quoteId);
            $quote = Mage::getModel('sales/quote')->load($quoteId);
        } else {
            $this->_helper->log("Current quote : ");
            $quote = $this->getCheckoutSession()->getQuote();
            $this->_helper->log("currentQuoteId: " . $quote->getId());
        }

        return $quote;
    }

    /**
     * Get Magento Quote by cartId
     *
     * @return object
     *
     */
    public function getQuoteById($quoteId)
    {
        $quote = null;
        if (isset($quoteId) && $quoteId > 0) {
            $this->_helper->log("getQuoteById id: " . $quoteId);
            $quote = Mage::getModel('sales/quote')->load($quoteId);
        }

        return $quote;
    }


    /**
     * Get Magento Quote by cartId
     *
     * @return object
     *
     */
    public function getCheckoutSession()
    {
        return $this->_helper->getCheckoutSession();
    }

    public function getCustomerSession()
    {
        return $this->_helper->getCustomerSession();
    }
}
