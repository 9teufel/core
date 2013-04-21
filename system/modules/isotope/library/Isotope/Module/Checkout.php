<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2012 Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://www.isotopeecommerce.com
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 */

namespace Isotope\Module;

use Isotope\Isotope;
use Isotope\Model\Address;
use Isotope\Model\Payment;
use Isotope\Model\Shipping;
use Isotope\Model\ProductCollection\Order;


/**
 * Class ModuleIsotopeCheckout
 * Front end module Isotope "checkout".
 *
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 */
class Checkout extends Module
{

    /**
     * Order data. Each checkout step can provide key-value (string) data for the order email.
     * @var array
     */
    public $arrOrderData = array();

    /**
     * Do not submit form
     * @var boolean
     */
    public $doNotSubmit = false;

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'mod_iso_checkout';

    /**
     * Disable caching of the frontend page if this module is in use.
     * @var boolean
     */
    protected $blnDisableCache = true;

    /**
     * Current step
     * @var string
     */
    protected $strCurrentStep;

    /**
     * Checkout info. Contains an overview about each step to show on the review page (eg. address, payment & shipping method).
     * @var array
     */
    protected $arrCheckoutInfo;

    /**
     * Form ID
     * @var string
     */
    protected $strFormId = 'iso_mod_checkout';


    /**
     * Display a wildcard in the back end
     * @return string
     */
    public function generate()
    {
        if (TL_MODE == 'BE')
        {
            $objTemplate = new \BackendTemplate('be_wildcard');

            $objTemplate->wildcard = '### ISOTOPE CHECKOUT ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

            return $objTemplate->parse();
        }

        // Set the step from the auto_item parameter
        if ($GLOBALS['TL_CONFIG']['useAutoItem'] && isset($_GET['auto_item']))
        {
            \Input::setGet('step', \Input::get('auto_item'));
        }

        // Do not cache the page
        global $objPage;
        $objPage->cache = 0;

        $this->strCurrentStep = \Input::get('step');

        return parent::generate();
    }


    /**
     * Returns the current form ID
     * @return string
     */
    public function getFormId()
    {
        return $this->strFormId;
    }


    /**
     * Generate module
     * @return void
     */
    protected function compile()
    {
        // Order has been completed (postsale request)
        if ($this->strCurrentStep == 'complete' && \Input::get('uid') != '')
        {
            if (($objOrder = Order::findOneByUniqid(\Input::get('uid'))) !== null)
            {
                // Order is complete, forward to confirmation page
                if ($objOrder->complete())
                {
                    \Isotope\Frontend::clearTimeout();

                    $this->redirect(\Isotope\Frontend::addQueryStringToUrl('uid=' . $objOrder->uniqid, $this->orderCompleteJumpTo));
                }

                // Order is not complete, wait for it
                if (\Isotope\Frontend::setTimeout())
                {
                    $this->Template = new \Isotope\Template('mod_message');
                    $this->Template->type = 'processing';
                    $this->Template->message = $GLOBALS['TL_LANG']['MSC']['payment_processing'];

                    return;
                }
            }
        }

        // Return error message if cart is empty
        if (!Isotope::getCart()->items)
        {
            $this->Template = new \Isotope\Template('mod_message');
            $this->Template->type = 'empty';
            $this->Template->message = $GLOBALS['TL_LANG']['MSC']['noItemsInCart'];

            return;
        }

        // Insufficient cart subtotal
        if (Isotope::getConfig()->cartMinSubtotal > 0 && Isotope::getConfig()->cartMinSubtotal > Isotope::getCart()->getSubtotal())
        {
            $this->Template = new \Isotope\Template('mod_message');
            $this->Template->type = 'error';
            $this->Template->message = sprintf($GLOBALS['TL_LANG']['ERR']['cartMinSubtotal'], Isotope::formatPriceWithCurrency(Isotope::getConfig()->cartMinSubtotal));

            return;
        }

        // Redirect to login page if not logged in
        if ($this->iso_checkout_method == 'member' && FE_USER_LOGGED_IN !== true)
        {
            $objPage = $this->Database->prepare("SELECT id,alias FROM tl_page WHERE id=?")->limit(1)->execute($this->iso_login_jumpTo);

            if (!$objPage->numRows)
            {
                $this->Template = new \Isotope\Template('mod_message');
                $this->Template->type = 'error';
                $this->Template->message = $GLOBALS['TL_LANG']['ERR']['isoLoginRequired'];

                return;
            }

            $this->redirect($this->generateFrontendUrl($objPage->row()));
        }
        elseif ($this->iso_checkout_method == 'guest' && FE_USER_LOGGED_IN === true)
        {
            $this->Template = new \Isotope\Template('mod_message');
            $this->Template->type = 'error';
            $this->Template->message = 'User checkout not allowed';

            return;
        }

        if (\Input::get('step') == '') {
            if ($this->iso_forward_review) {
                $this->redirect($this->addToUrl('step=review', true));
            }

            $this->redirectToNextStep();
        }

        // Default template settings. Must be set at beginning so they can be overwritten later (eg. trough callback)
        $this->Template->action = ampersand(\Environment::get('request'), ENCODE_AMPERSANDS);
        $this->Template->formId = $this->strFormId;
        $this->Template->formSubmit = $this->strFormId;
        $this->Template->enctype = 'application/x-www-form-urlencoded';
        $this->Template->previousLabel = specialchars($GLOBALS['TL_LANG']['MSC']['previousStep']);
        $this->Template->nextLabel = specialchars($GLOBALS['TL_LANG']['MSC']['nextStep']);
        $this->Template->nextClass = 'next';
        $this->Template->showPrevious = true;
        $this->Template->showNext = true;
        $this->Template->showForm = true;

        // Remove shipping step if no items are shipped
        if (!Isotope::getCart()->requiresShipping())
        {
            unset($GLOBALS['ISO_CHECKOUT_STEPS']['shipping']);

            // Remove payment step if items are free of charge. We need to do this here because shipping might have a price.
            if (!Isotope::getCart()->requiresPayment())
            {
                unset($GLOBALS['ISO_CHECKOUT_STEPS']['payment']);
            }
        }

        if ($this->strCurrentStep == 'failed')
        {
            $this->Database->prepare("UPDATE tl_iso_product_collection SET order_status=? WHERE source_collection_id=?")->execute(Isotope::getConfig()->orderstatus_error, Isotope::getCart()->id);
            $this->Template->mtype = 'error';
            $this->Template->message = strlen(\Input::get('reason')) ? \Input::get('reason') : $GLOBALS['TL_LANG']['ERR']['orderFailed'];
            $this->strCurrentStep = 'review';
        }

        // Run trough all steps until we find the current one or one reports failure
        $intCurrentStep = 0;
        $intTotalSteps = count($GLOBALS['ISO_CHECKOUT_STEPS']);
        foreach ($GLOBALS['ISO_CHECKOUT_STEPS'] as $step => $arrCallbacks)
        {
            // Step could be removed while looping
            if (!isset($GLOBALS['ISO_CHECKOUT_STEPS'][$step]))
            {
                --$intTotalSteps;
                continue;
            }

            $this->strFormId = 'iso_mod_checkout_' . $step;
            $this->Template->formId = $this->strFormId;
            $this->Template->formSubmit = $this->strFormId;
            ++$intCurrentStep;
            $strBuffer = '';

            foreach ($arrCallbacks as $callback)
            {
                if ($callback[0] == 'ModuleIsotopeCheckout')
                {
                    $strBuffer .= $this->{$callback[1]}();
                }
                else
                {
                    $this->import($callback[0]);
                    $strBuffer .= $this->{$callback[0]}->{$callback[1]}($this);
                }

                // the user wanted to proceed but the current step is not completed yet
                if ($this->doNotSubmit && $step != $this->strCurrentStep)
                {
                    $this->redirect($this->addToUrl('step=' . $step, true));
                }
            }

            if ($step == $this->strCurrentStep)
            {
                global $objPage;
                $objPage->pageTitle = sprintf($GLOBALS['TL_LANG']['MSC']['checkoutStep'], $intCurrentStep, $intTotalSteps, (strlen($GLOBALS['TL_LANG']['MSC']['checkout_' . $step]) ? $GLOBALS['TL_LANG']['MSC']['checkout_' . $step] : $step)) . ($objPage->pageTitle ? $objPage->pageTitle : $objPage->title);
                break;
            }
        }

        if ($this->strCurrentStep == 'process')
        {
            $this->writeOrder();
            $strBuffer = Isotope::getCart()->hasPayment() ? Isotope::getCart()->Payment->checkoutForm($this) : false;

            if ($strBuffer === false)
            {
                $this->redirect($this->addToUrl('step=complete', true));
            }

            $this->Template->showForm = false;
            $this->doNotSubmit = true;
        }

        if ($this->strCurrentStep == 'complete')
        {
            $strBuffer = Isotope::getCart()->hasPayment() ? Isotope::getCart()->Payment->processPayment() : true;

            if ($strBuffer === true)
            {
                // If checkout is successful, complete order and redirect to confirmation page
                if (($objOrder = Order::findOneBy('source_collection_id', Isotope::getCart()->id)) !== null && $objOrder->checkout() && $objOrder->complete())
                {
                    $this->redirect(\Isotope\Frontend::addQueryStringToUrl('uid=' . $objOrder->uniqid, $this->orderCompleteJumpTo));
                }

                // Checkout failed, show error message
                $this->redirect($this->addToUrl('step=failed', true));
            }
            elseif ($strBuffer === false)
            {
                $this->redirect($this->addToUrl('step=failed', true));
            }
            else
            {
                $this->Template->showNext = false;
                $this->Template->showPrevious = false;
            }
        }

        $this->Template->fields = $strBuffer;

        if (!strlen($this->strCurrentStep))
        {
            $this->strCurrentStep = $step;
        }

        // Show checkout steps
        $arrStepKeys = array_keys($GLOBALS['ISO_CHECKOUT_STEPS']);
        $blnPassed = true;
        $total = count($arrStepKeys) - 1;
        $arrSteps = array();

        if ($this->strCurrentStep != 'process' && $this->strCurrentStep != 'complete')
        {
            foreach ($arrStepKeys as $i => $step)
            {
                if ($this->strCurrentStep == $step)
                {
                    $blnPassed = false;
                }

                $blnActive = $this->strCurrentStep == $step ? true : false;

                $arrSteps[] = array
                (
                    'isActive'    => $blnActive,
                    'class'        => 'step_' . $i . (($i == 0) ? ' first' : '') . ($i == $total ? ' last' : '') . ($blnActive ? ' active' : '') . ($blnPassed ? ' passed' : '') . ((!$blnPassed && !$blnActive) ? ' upcoming' : '') . ' '. $step,
                    'label'        => (strlen($GLOBALS['TL_LANG']['MSC']['checkout_' . $step]) ? $GLOBALS['TL_LANG']['MSC']['checkout_' . $step] : $step),
                    'href'        => ($blnPassed ? $this->addToUrl('step=' . $step, true) : ''),
                    'title'        => specialchars(sprintf($GLOBALS['TL_LANG']['MSC']['checkboutStepBack'], (strlen($GLOBALS['TL_LANG']['MSC']['checkout_' . $step]) ? $GLOBALS['TL_LANG']['MSC']['checkout_' . $step] : $step))),
                );
            }
        }

        $this->Template->steps = $arrSteps;
        $this->Template->activeStep = $GLOBALS['TL_LANG']['MSC']['activeStep'];

        // Hide back buttons it this is the first step
        if (array_search($this->strCurrentStep, $arrStepKeys) === 0)
        {
            $this->Template->showPrevious = false;
        }

        // Show "confirm order" button if this is the last step
        elseif (array_search($this->strCurrentStep, $arrStepKeys) === $total)
        {
            $this->Template->nextClass = 'confirm';
            $this->Template->nextLabel = specialchars($GLOBALS['TL_LANG']['MSC']['confirmOrder']);
        }

        // User pressed "back" button
        if (strlen(\Input::post('previousStep')))
        {
            $this->redirectToPreviousStep();
        }

        // Valid input data, redirect to next step
        elseif (\Input::post('FORM_SUBMIT') == $this->strFormId && !$this->doNotSubmit)
        {
            $this->redirectToNextStep();
        }
    }


    /**
     * Redirect visitor to the next step in ISO_CHECKOUT_STEPS
     * @return void
     */
    protected function redirectToNextStep()
    {
        $arrSteps = array_keys($GLOBALS['ISO_CHECKOUT_STEPS']);

        if (!in_array($this->strCurrentStep, $arrSteps))
        {
            $this->redirect($this->addToUrl('step='.array_shift($arrSteps), true));
        }
        else
        {
            // key of the next step
            $intKey = array_search($this->strCurrentStep, $arrSteps) + 1;

            // redirect to step "process" if the next step is the last one
            if ($intKey == count($arrSteps))
            {
                $this->redirect($this->addToUrl('step=process', true));
            }
            else
            {
                $this->redirect($this->addToUrl('step='.$arrSteps[$intKey], true));
            }
        }
    }


    /**
     * Redirect visotor to the previous step in ISO_CHECKOUT_STEPS
     * @return void
     */
    protected function redirectToPreviousStep()
    {
        $arrSteps = array_keys($GLOBALS['ISO_CHECKOUT_STEPS']);

        if (!in_array($this->strCurrentStep, $arrSteps))
        {
            $this->redirect($this->addToUrl('step='.array_shift($arrSteps), true));
        }
        else
        {
            $strKey = array_search($this->strCurrentStep, $arrSteps);
            $this->redirect($this->addToUrl('step='.$arrSteps[($strKey-1)], true));
        }
    }


    /**
     * Generate billing address interface and return it as HTML string
     * @param boolean
     * @return string
     */
    protected function getBillingAddressInterface($blnReview=false)
    {
        $blnRequiresPayment = Isotope::getCart()->requiresPayment();

        if ($blnReview)
        {
            $blnRequiresShipping = Isotope::getCart()->requiresShipping();
            $objAddress = Isotope::getCart()->getShippingAddress();

            $strHeadline = $GLOBALS['TL_LANG']['MSC']['billing_address'];

            if ($blnRequiresPayment && $blnRequiresShipping && $objAddress->id == -1)
            {
                $strHeadline = $GLOBALS['TL_LANG']['MSC']['billing_shipping_address'];
            }
            elseif ($blnRequiresShipping && $objAddress->id == -1)
            {
                $strHeadline = $GLOBALS['TL_LANG']['MSC']['shipping_address'];
            }
            elseif (!$blnRequiresPayment && !$blnRequiresShipping)
            {
                $strHeadline = $GLOBALS['TL_LANG']['MSC']['customer_address'];
            }

            return array('billing_address' => array
            (
                'headline'    => $strHeadline,
                'info'        => Isotope::getCart()->getBillingAddress()->generateHtml(Isotope::getConfig()->billing_fields),
                'edit'        => $this->addToUrl('step=address', true),
            ));
        }

        $objTemplate = new \Isotope\Template('iso_checkout_billing_address');

        $objTemplate->headline = $blnRequiresPayment ? $GLOBALS['TL_LANG']['MSC']['billing_address'] : $GLOBALS['TL_LANG']['MSC']['customer_address'];
        $objTemplate->message = (FE_USER_LOGGED_IN === true ? $GLOBALS['TL_LANG']['MSC'][($blnRequiresPayment ? 'billing' : 'customer') . '_address_message'] : $GLOBALS['TL_LANG']['MSC'][($blnRequiresPayment ? 'billing' : 'customer') . '_address_guest_message']);
        $objTemplate->fields = $this->generateAddressWidget('billing_address');

        if (!$this->doNotSubmit)
        {
            $objAddress = Isotope::getCart()->getBillingAddress();

            $this->arrOrderData['billing_address'] = $objAddress->generateHtml(Isotope::getConfig()->billing_fields);
            $this->arrOrderData['billing_address_text'] = $objAddress->generateText(Isotope::getConfig()->billing_fields);
        }

        return $objTemplate->parse();
    }


    /**
     * Generate shipping address interface and return it as HTML string
     * @param boolean
     * @return string
     */
    protected function getShippingAddressInterface($blnReview=false)
    {
        if (!Isotope::getCart()->requiresShipping() || count(Isotope::getConfig()->shipping_fields_raw) == 0)
        {
            return '';
        }

        $objAddress = Isotope::getCart()->getShippingAddress();

        if ($blnReview)
        {
            if ($objAddress->id == -1)
            {
                return false;
            }

            return array('shipping_address' => array
            (
                'headline'    => $GLOBALS['TL_LANG']['MSC']['shipping_address'],
                'info'        => $objAddress->generateHtml(Isotope::getConfig()->shipping_fields),
                'edit'        => $this->addToUrl('step=address', true),
            ));
        }

        $objTemplate = new \Isotope\Template('iso_checkout_shipping_address');

        $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['shipping_address'];
        $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['shipping_address_message'];
        $objTemplate->fields =  $this->generateAddressWidget('shipping_address');

        if (!$this->doNotSubmit)
        {
            // No shipping address, use billing address
            if ($objAddress->id == -1)
            {
                $strShippingAddress = (Isotope::getCart()->requiresPayment() ? $GLOBALS['TL_LANG']['MSC']['useBillingAddress'] : $GLOBALS['TL_LANG']['MSC']['useCustomerAddress']);

                $this->arrOrderData['shipping_address'] = $strShippingAddress;
                $this->arrOrderData['shipping_address_text'] = $strShippingAddress;
            }
            else
            {
                $this->arrOrderData['shipping_address'] = $objAddress->generateHtml(Isotope::getConfig()->shipping_fields);
                $this->arrOrderData['shipping_address_text'] = $objAddress->generateText(Isotope::getConfig()->shipping_fields);
            }
        }

        return $objTemplate->parse();
    }


    /**
     * Generate shipping modules interface and return it as HTML string
     * @param boolean
     * @return string
     */
    protected function getShippingModulesInterface($blnReview=false)
    {
        if ($blnReview)
        {
            if (!Isotope::getCart()->hasShipping())
            {
                return false;
            }

            return array
            (
                'shipping_method' => array
                (
                    'headline'    => $GLOBALS['TL_LANG']['MSC']['shipping_method'],
                    'info'        => Isotope::getCart()->getShippingMethod()->checkoutReview(),
                    'note'        => Isotope::getCart()->getShippingMethod()->note,
                    'edit'        => $this->addToUrl('step=shipping', true),
                ),
            );
        }

        $arrModules = array();
        $arrModuleIds = deserialize($this->iso_shipping_modules);

        if (is_array($arrModuleIds) && !empty($arrModuleIds)) {

            $arrData = \Input::post('shipping');
            $arrModuleIds = array_map('intval', $arrModuleIds);

            $objModules = Shipping::findBy(array('id IN (' . implode(',', $arrModuleIds) . ')', (BE_USER_LOGGED_IN === true ? '' : "enabled='1'")), null, array('order'=>$this->Database->findInSet('id', $arrModuleIds)));

            while ($objModules->next())
            {
                $objModule = $objModules->current();

                if (!$objModule->isAvailable()) {
                    continue;
                }

                if (is_array($arrData) && $arrData['module'] == $objModule->id) {
                    $_SESSION['CHECKOUT_DATA']['shipping'] = $arrData;
                }

                if (is_array($_SESSION['CHECKOUT_DATA']['shipping']) && $_SESSION['CHECKOUT_DATA']['shipping']['module'] == $objModule->id) {
                    Isotope::getCart()->setShippingMethod($objModule);
                }

                $fltPrice = $objModule->price;
                $strSurcharge = $objModule->surcharge;
                $strPrice = $fltPrice != 0 ? (($strSurcharge == '' ? '' : ' ('.$strSurcharge.')') . ': '.Isotope::formatPriceWithCurrency($fltPrice)) : '';

                $arrModules[] = array(
                    'id'        => $objModule->id,
                    'label'     => $objModule->label,
                    'price'     => $strPrice,
                    'checked'   => ((Isotope::getCart()->getShippingMethod()->id == $objModule->id || $objModules->numRows == 1) ? ' checked="checked"' : ''),
                    'note'      => $objModule->note,
                    'form'      => $objModule->getShippingOptions($this),
                );

                $objLastModule = $objModule;
            }
        }

        if (empty($arrModules)) {
            $this->doNotSubmit = true;
            $this->Template->showNext = false;

            $objTemplate = new \Isotope\Template('mod_message');
            $objTemplate->class = 'shipping_method';
            $objTemplate->hl = 'h2';
            $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['shipping_method'];
            $objTemplate->type = 'error';
            $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['noShippingModules'];

            return $objTemplate->parse();
        }

        $objTemplate = new \Isotope\Template('iso_checkout_shipping_method');

        if (!Isotope::getCart()->hasShipping() && !strlen($_SESSION['CHECKOUT_DATA']['shipping']['module']) && count($arrModules) == 1) {

            Isotope::getCart()->setShippingMethod($objLastModule);
            $_SESSION['CHECKOUT_DATA']['shipping']['module'] = Isotope::getCart()->getShippingMethod()->id;
            $arrModules[0]['checked'] = ' checked="checked"';

        } elseif (!Isotope::getCart()->hasShipping()) {

            if (\Input::post('FORM_SUBMIT') != '') {
                $objTemplate->error = $GLOBALS['TL_LANG']['MSC']['shipping_method_missing'];
            }

            $this->doNotSubmit = true;
        }

        $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['shipping_method'];
        $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['shipping_method_message'];
        $objTemplate->shippingMethods = $arrModules;

        if (!$this->doNotSubmit) {
            $objShipping = Isotope::getCart()->getShippingMethod();
            $this->arrOrderData['shipping_method_id']   = $objShipping->id;
            $this->arrOrderData['shipping_method']      = $objShipping->label;
            $this->arrOrderData['shipping_note']        = $objShipping->note;
            $this->arrOrderData['shipping_note_text']   = strip_tags($objShipping->note);
        }

        // Remove payment step if items are free of charge
        if (!Isotope::getCart()->requiresPayment()) {
            unset($GLOBALS['ISO_CHECKOUT_STEPS']['payment']);
        }

        return $objTemplate->parse();
    }


    /**
     * Generate payment modules interface and return it as HTML string
     * @param boolean
     * @return string
     */
    protected function getPaymentModulesInterface($blnReview=false)
    {
        if ($blnReview) {
            if (!Isotope::getCart()->hasPayment()) {
                return false;
            }

            return array(
                'payment_method' => array(
                    'headline'    => $GLOBALS['TL_LANG']['MSC']['payment_method'],
                    'info'        => Isotope::getCart()->Payment->checkoutReview(),
                    'note'        => Isotope::getCart()->Payment->note,
                    'edit'        => $this->addToUrl('step=payment', true),
                ),
            );
        }

        $arrModules = array();
        $arrModuleIds = deserialize($this->iso_payment_modules);

        if (is_array($arrModuleIds) && !empty($arrModuleIds)) {

            $arrData = \Input::post('payment');
            $arrModuleIds = array_map('intval', $arrModuleIds);

            $objModules = Payment::findBy(array('id IN (' . implode(',', $arrModuleIds) . ')', (BE_USER_LOGGED_IN === true ? '' : "enabled='1'")), null, array('order'=>$this->Database->findInSet('id', $arrModuleIds)));

            while ($objModules->next()) {

                $objModule = $objModules->current();

                if (!$objModule->isAvailable()) {
                    continue;
                }

                if (is_array($arrData) && $arrData['module'] == $objModule->id) {
                    $_SESSION['CHECKOUT_DATA']['payment'] = $arrData;
                }

                if (is_array($_SESSION['CHECKOUT_DATA']['payment']) && $_SESSION['CHECKOUT_DATA']['payment']['module'] == $objModule->id) {
                    Isotope::getCart()->Payment = $objModule;
                }

                $fltPrice = $objModule->price;
                $strSurcharge = $objModule->surcharge;
                $strPrice = ($fltPrice != 0) ? (($strSurcharge == '' ? '' : ' ('.$strSurcharge.')') . ': '.Isotope::formatPriceWithCurrency($fltPrice)) : '';

                $arrModules[] = array(
                    'id'        => $objModule->id,
                    'label'     => $objModule->label,
                    'price'     => $strPrice,
                    'checked'   => ((Isotope::getCart()->Payment->id == $objModule->id || $objModules->numRows == 1) ? ' checked="checked"' : ''),
                    'note'      => $objModule->note,
                    'form'      => $objModule->paymentForm($this),
                );

                $objLastModule = $objModule;
            }
        }

        if (empty($arrModules)) {
            $this->doNotSubmit = true;
            $this->Template->showNext = false;

            $objTemplate = new \Isotope\Template('mod_message');
            $objTemplate->class = 'payment_method';
            $objTemplate->hl = 'h2';
            $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['payment_method'];
            $objTemplate->type = 'error';
            $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['noPaymentModules'];

            return $objTemplate->parse();
        }

        $objTemplate = new \Isotope\Template('iso_checkout_payment_method');

        if (!Isotope::getCart()->hasPayment() && !strlen($_SESSION['CHECKOUT_DATA']['payment']['module']) && count($arrModules) == 1) {

            Isotope::getCart()->Payment = $objLastModule;
            $_SESSION['CHECKOUT_DATA']['payment']['module'] = Isotope::getCart()->Payment->id;
            $arrModules[0]['checked'] = ' checked="checked"';

        } elseif (!Isotope::getCart()->hasPayment()) {

            if (\Input::post('FORM_SUBMIT') != '') {
                $objTemplate->error = $GLOBALS['TL_LANG']['MSC']['payment_method_missing'];
            }

            $this->doNotSubmit = true;
        }

        $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['payment_method'];
        $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['payment_method_message'];
        $objTemplate->paymentMethods = $arrModules;

        if (!$this->doNotSubmit) {
            $objPayment = Isotope::getCart()->getPaymentMethod();
            $this->arrOrderData['payment_method_id']    = $objPayment->id;
            $this->arrOrderData['payment_method']       = $objPayment->label;
            $this->arrOrderData['payment_note']         = $objPayment->note;
            $this->arrOrderData['payment_note_text']    = strip_tags($objPayment->note);
        }

        return $objTemplate->parse();
    }


    /**
     * Generate order conditions interface if shown on top (before address)
     * @param boolean
     * @return string
     */
    protected function getOrderConditionsOnTop($blnReview=false)
    {
        if ($this->iso_order_conditions_position == 'top') {
            return $this->getOrderConditionsInterface($blnReview);
        }

        return '';
    }


    /**
     * Generate order conditions interface if shown before products
     * @param boolean
     * @return string
     */
    protected function getOrderConditionsBeforeProducts($blnReview=false)
    {
        if ($this->iso_order_conditions_position == 'before') {
            return $this->getOrderConditionsInterface($blnReview);
        }

        return '';
    }


    /**
     * Generate order conditions interface if shown after products
     * @param boolean
     * @return string
     */
    protected function getOrderConditionsAfterProducts($blnReview=false)
    {
        if ($this->iso_order_conditions_position == 'after') {
            return $this->getOrderConditionsInterface($blnReview);
        }

        return '';
    }


    /**
     * Generate order conditions interface and return it as HTML string
     * @param boolean
     * @return string
     */
    protected function getOrderConditionsInterface($blnReview=false)
    {
        if (!$this->iso_order_conditions) {
            return '';
        }

        if ($blnReview)
        {
            if (!$this->doNotSubmit)
            {
                if (is_array($_SESSION['FORM_DATA']))
                {
                    foreach( $_SESSION['FORM_DATA'] as $name => $value )
                    {
                        $this->arrOrderData['form_' . $name] = $value;
                    }
                }

                if (is_array($_SESSION['FILES']))
                {
                    foreach( $_SESSION['FILES'] as $name => $file )
                    {
                        $this->arrOrderData['form_' . $name] = \Environment::get('base') . str_replace(TL_ROOT . '/', '', dirname($file['tmp_name'])) . '/' . rawurlencode($file['name']);
                    }
                }
            }

            return '';
        }

        $this->import('Isotope\Frontend', 'IsotopeFrontend');
        $objForm = $this->IsotopeFrontend->prepareForm($this->iso_order_conditions, $this->strFormId);

        // Form not found
        if ($objForm == null)
        {
            return '';
        }

        $this->doNotSubmit = $objForm->blnHasErrors ? true : $this->doNotSubmit;
        $this->Template->enctype = $objForm->enctype;

        if (!$this->doNotSubmit)
        {
            foreach ($objForm->arrFormData as $name => $value)
            {
                $this->arrOrderData['form_' . $name] = $value;
            }

            foreach ($objForm->arrFiles as $name => $file)
            {
                $this->arrOrderData['form_' . $name] = \Environment::get('base') . str_replace(TL_ROOT . '/', '', dirname($file['tmp_name'])) . '/' . rawurlencode($file['name']);
            }
        }

        $objTemplate = new \Isotope\Template('iso_checkout_order_conditions');
        $objTemplate->attributes    = $objForm->attributes;
        $objTemplate->tableless        = $objForm->arrData['tableless'];

        $parse = create_function('$a', 'return $a->parse();');
        $objTemplate->hidden = implode('', array_map($parse, $objForm->arrHidden));
        $objTemplate->fields = implode('', array_map($parse, $objForm->arrFields));

        return $objTemplate->parse();
    }


    /**
     * Generate order review interface and return it as HTML string
     * @param boolean
     * @return string
     */
    protected function getOrderInfoInterface($blnReview=false)
    {
        if ($blnReview)
        {
            return;
        }

        $objTemplate = new \Isotope\Template('iso_checkout_order_info');
        $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['order_review'];
        $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['order_review_message'];
        $objTemplate->summary = $GLOBALS['TL_LANG']['MSC']['cartSummary'];
        $objTemplate->info = $this->getCheckoutInfo();
        $objTemplate->edit_info = $GLOBALS['TL_LANG']['MSC']['changeCheckoutInfo'];

        return $objTemplate->parse();
    }


    /**
     * Generate list of products for the order review page
     * @param bool
     * @return string
     */
    protected function getOrderProductsInterface($blnReview=false)
    {
        if ($blnReview)
        {
            return;
        }

        $objTemplate = new \Isotope\Template('iso_checkout_order_products');

        // Surcharges must be initialized before getProducts() to apply tax_id to each product
        $arrSurcharges = Isotope::getCart()->getSurcharges();
        $arrProductData = array();
        $arrProducts = Isotope::getCart()->getProducts();

        foreach ($arrProducts as $objProduct)
        {
            $arrProductData[] = array_merge($objProduct->getAttributes(), array
            (
                'id'                => $objProduct->id,
                'image'                => $objProduct->images->main_image,
                'link'                => $objProduct->href_reader,
                'price'                => Isotope::formatPriceWithCurrency($objProduct->price),
                'tax_free_price'    => Isotope::formatPriceWithCurrency($objProduct->tax_free_price),
                'total_price'        => Isotope::formatPriceWithCurrency($objProduct->total_price),
                'tax_free_total_price'    => Isotope::formatPriceWithCurrency($objProduct->tax_free_total_price),
                'quantity'            => $objProduct->quantity_requested,
                'tax_id'            => $objProduct->tax_id,
                'product_options'    => Isotope::formatOptions($objProduct->getOptions()),
            ));
        }

        $objTemplate->collection = Isotope::getCart();
        $objTemplate->products = \Isotope\Frontend::generateRowClass($arrProductData, 'row', 'rowClass', 0, ISO_CLASS_COUNT|ISO_CLASS_FIRSTLAST|ISO_CLASS_EVENODD);
        $objTemplate->surcharges = \Isotope\Frontend::formatSurcharges($arrSurcharges);
        $objTemplate->subTotalLabel = $GLOBALS['TL_LANG']['MSC']['subTotalLabel'];
        $objTemplate->grandTotalLabel = $GLOBALS['TL_LANG']['MSC']['grandTotalLabel'];
        $objTemplate->subTotalPrice = Isotope::formatPriceWithCurrency(Isotope::getCart()->getSubtotal());
        $objTemplate->grandTotalPrice = Isotope::formatPriceWithCurrency(Isotope::getCart()->getTotal());

        return $objTemplate->parse();
    }


    /**
     * Save the order
     * @return void
     */
    protected function writeOrder()
    {
        $objCart = Isotope::getCart();
        $objOrder = Order::findOneBy('source_collection_id', $objCart->id);

        if (null === $objOrder) {
            $objOrder = new Order();
        }

        $objOrder->setSourceCollection($objCart);

        $objOrder->checkout_info        = $this->getCheckoutInfo();
        $objOrder->iso_sales_email      = $this->iso_sales_email ? $this->iso_sales_email : (($GLOBALS['TL_ADMIN_NAME'] != '') ? sprintf('%s <%s>', $GLOBALS['TL_ADMIN_NAME'], $GLOBALS['TL_ADMIN_EMAIL']) : $GLOBALS['TL_ADMIN_EMAIL']);
        $objOrder->iso_mail_admin       = $this->iso_mail_admin;
        $objOrder->iso_mail_customer    = $this->iso_mail_customer;
        $objOrder->iso_addToAddressbook = $this->iso_addToAddressbook;
        $objOrder->email_data = $this->arrOrderData;

        $objOrder->save();
    }


    /**
     * Generate address widget and return it as HTML string
     * @param string
     * @return string
     */
    protected function generateAddressWidget($field)
    {
        $strBuffer = '';
        $arrOptions = array();
        $blnHasAddress = false;
        $arrCountries = ($field == 'billing_address' ? Isotope::getConfig()->billing_countries : Isotope::getConfig()->shipping_countries);

        if (FE_USER_LOGGED_IN === true)
        {
            $objAddresses = Address::findForMember($this->User->id, array('order'=>'isDefaultBilling DESC, isDefaultShipping DESC'));

            if (null !== $objAddresses) {
                while ($objAddresses->next()) {

                    if (is_array($arrCountries) && !in_array($objAddresses->country, $arrCountries)) {
                        continue;
                    }

                    $objAddress = $objAddresses->current();

                    $arrOptions[] = array
                    (
                        'value'        => $objAddress->id,
                        'label'        => $objAddress->generateHtml(($field == 'billing_address' ? Isotope::getConfig()->billing_fields : Isotope::getConfig()->shipping_fields)),
                    );

                    $blnHasAddress = true;
                }
            }
        }

        switch ($field)
        {
            case 'shipping_address':
                $arrAddress = $_SESSION['CHECKOUT_DATA'][$field] ? $_SESSION['CHECKOUT_DATA'][$field] : Isotope::getCart()->shipping_address;
                $intDefaultValue = strlen($arrAddress['id']) ? $arrAddress['id'] : -1;

                array_insert($arrOptions, 0, array(array
                (
                    'value'    => -1,
                    'label' => (Isotope::getCart()->requiresPayment() ? $GLOBALS['TL_LANG']['MSC']['useBillingAddress'] : $GLOBALS['TL_LANG']['MSC']['useCustomerAddress']),
                )));

                $arrOptions[] = array
                (
                    'value'    => 0,
                    'label' => $GLOBALS['TL_LANG']['MSC']['differentShippingAddress'],
                );
                break;

            case 'billing_address':
            default:
                $arrAddress = $_SESSION['CHECKOUT_DATA'][$field] ? $_SESSION['CHECKOUT_DATA'][$field] : Isotope::getCart()->billing_address;
                $intDefaultValue = strlen($arrAddress['id']) ? $arrAddress['id'] : 0;

                if ($blnHasAddress)
                {
                    $arrOptions[] = array
                    (
                        'value'    => 0,
                        'label' => &$GLOBALS['TL_LANG']['MSC']['createNewAddressLabel'],
                    );
                }
                break;
        }

        // !HOOK: add custom addresses
        if (isset($GLOBALS['ISO_HOOKS']['addCustomAddress']) && is_array($GLOBALS['ISO_HOOKS']['addCustomAddress']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['addCustomAddress'] as $callback)
            {
                $this->import($callback[0]);
                $arrOptions = $this->$callback[0]->$callback[1]($arrOptions, $field, $intDefaultValue, $this);
            }
        }

        if (!empty($arrOptions))
        {
            $strClass = $GLOBALS['TL_FFL']['radio'];

            $arrData = array('id'=>$field, 'name'=>$field, 'mandatory'=>true);

            $objWidget = new $strClass($arrData);
            $objWidget->options = $arrOptions;
            $objWidget->value = $intDefaultValue;
            $objWidget->onclick = "Isotope.toggleAddressFields(this, '" . $field . "_new');";
            $objWidget->storeValues = true;
            $objWidget->tableless = true;

            // Validate input
            if (\Input::post('FORM_SUBMIT') == $this->strFormId)
            {
                $objWidget->validate();

                if ($objWidget->hasErrors())
                {
                    $this->doNotSubmit = true;
                }
                else
                {
                    $_SESSION['CHECKOUT_DATA'][$field]['id'] = $objWidget->value;
                }
            }
            elseif ($objWidget->value != '')
            {
                \Input::setPost($objWidget->name, $objWidget->value);

                $objValidator = clone $objWidget;
                $objValidator->validate();

                if ($objValidator->hasErrors())
                {
                    $this->doNotSubmit = true;
                }
            }

            $strBuffer .= $objWidget->parse();
        }

        if (strlen($_SESSION['CHECKOUT_DATA'][$field]['id']))
        {
            Isotope::getCart()->$field = $_SESSION['CHECKOUT_DATA'][$field]['id'];
        }
        elseif (FE_USER_LOGGED_IN !== true)
        {

            //$this->doNotSubmit = true;
        }

        $strBuffer .= '<div id="' . $field . '_new" class="address_new"' . (((FE_USER_LOGGED_IN !== true && $field == 'billing_address') || $objWidget->value == 0) ? '>' : ' style="display:none">');
        $strBuffer .= '<span>' . $this->generateAddressWidgets($field, count($arrOptions)) . '</span>';
        $strBuffer .= '</div>';

        return $strBuffer;
    }


    /**
     * Generate the current step widgets.
     * strResourceTable is used either to load a DCA or else to gather settings related to a given DCA.
     *
     * @todo <table...> was in a template, but I don't get why we need to define the table here?
     * @param string
     * @param integer
     * @return string
     */
    protected function generateAddressWidgets($strAddressType, $intOptions)
    {
        $arrWidgets = array();

        \System::loadLanguageFile('tl_iso_addresses');
        $this->loadDataContainer('tl_iso_addresses');

        $arrFields = ($strAddressType == 'billing_address' ? Isotope::getConfig()->billing_fields : Isotope::getConfig()->shipping_fields);
        $arrDefault = Isotope::getCart()->$strAddressType;

        if ($arrDefault['id'] == -1)
        {
            $arrDefault = array();
        }

        foreach ($arrFields as $field)
        {
            $arrData = $GLOBALS['TL_DCA']['tl_iso_addresses']['fields'][$field['value']];

            if (!is_array($arrData) || !$arrData['eval']['feEditable'] || !$field['enabled'] || ($arrData['eval']['membersOnly'] && FE_USER_LOGGED_IN !== true))
            {
                continue;
            }

            $strClass = $GLOBALS['TL_FFL'][$arrData['inputType']];

            // Continue if the class is not defined
            if (!$this->classFileExists($strClass))
            {
                continue;
            }

            // Special field "country"
            if ($field['value'] == 'country')
            {
                $arrCountries = ($strAddressType == 'billing_address' ? Isotope::getConfig()->billing_countries : Isotope::getConfig()->shipping_countries);
                $arrData['options'] = array_values(array_intersect($arrData['options'], $arrCountries));

                if ($arrDefault['country'] == '')
                {
                    $arrDefault['country'] = ($strAddressType == 'billing_address' ? Isotope::getConfig()->billing_country : Isotope::getConfig()->shipping_country);
                }
            }

            // Special field type "conditionalselect"
            elseif (strlen($arrData['eval']['conditionField']))
            {
                $arrData['eval']['conditionField'] = $strAddressType . '_' . $arrData['eval']['conditionField'];
            }

            // Special fields "isDefaultBilling" & "isDefaultShipping"
            elseif (($field['value'] == 'isDefaultBilling' && $strAddressType == 'billing_address' && $intOptions < 2) || ($field['value'] == 'isDefaultShipping' && $strAddressType == 'shipping_address' && $intOptions < 3))
            {
                $arrDefault[$field['value']] = '1';
            }

            $objWidget = new $strClass($this->prepareForWidget($arrData, $strAddressType . '_' . $field['value'], (strlen($_SESSION['CHECKOUT_DATA'][$strAddressType][$field['value']]) ? $_SESSION['CHECKOUT_DATA'][$strAddressType][$field['value']] : $arrDefault[$field['value']])));

            $objWidget->mandatory = $field['mandatory'] ? true : false;
            $objWidget->required = $objWidget->mandatory;
            $objWidget->tableless = $this->tableless;
            $objWidget->label = $field['label'] ? Isotope::translate($field['label']) : $objWidget->label;
            $objWidget->storeValues = true;

            // Validate input
            if (\Input::post('FORM_SUBMIT') == $this->strFormId && (\Input::post($strAddressType) === '0' || \Input::post($strAddressType) == ''))
            {
                $objWidget->validate();
                $varValue = $objWidget->value;

                // Convert date formats into timestamps
                if (strlen($varValue) && in_array($arrData['eval']['rgxp'], array('date', 'time', 'datim')))
                {
                    $objDate = new \Date($varValue, $GLOBALS['TL_CONFIG'][$arrData['eval']['rgxp'] . 'Format']);
                    $varValue = $objDate->tstamp;
                }

                // Do not submit if there are errors
                if ($objWidget->hasErrors())
                {
                    $this->doNotSubmit = true;
                }

                // Store current value
                elseif ($objWidget->submitInput())
                {
                    $arrAddress[$field['value']] = $varValue;
                }
            }
            elseif (\Input::post($strAddressType) === '0' || \Input::post($strAddressType) == '')
            {
                \Input::setPost($objWidget->name, $objWidget->value);

                $objValidator = clone $objWidget;
                $objValidator->validate();

                if ($objValidator->hasErrors())
                {
                    $this->doNotSubmit = true;
                }
            }

            $arrWidgets[] = $objWidget;
        }

        $arrWidgets = \Isotope\Frontend::generateRowClass($arrWidgets, 'row', 'rowClass', 0, ISO_CLASS_COUNT|ISO_CLASS_FIRSTLAST|ISO_CLASS_EVENODD);

        // Validate input
        if (\Input::post('FORM_SUBMIT') == $this->strFormId && !$this->doNotSubmit && is_array($arrAddress) && !empty($arrAddress))
        {
            $arrAddress['id'] = 0;
            $_SESSION['CHECKOUT_DATA'][$strAddressType] = $arrAddress;
        }

        if (is_array($_SESSION['CHECKOUT_DATA'][$strAddressType]) && $_SESSION['CHECKOUT_DATA'][$strAddressType]['id'] === 0)
        {
            Isotope::getCart()->$strAddressType = $_SESSION['CHECKOUT_DATA'][$strAddressType];
        }

        $strBuffer = '';

        foreach ($arrWidgets as $objWidget)
        {
            $strBuffer .= $objWidget->parse();
        }

        if ($this->tableless)
        {
            return $strBuffer;
        }

        return '
<table>
' . $strBuffer . '
</table>';
    }


    /**
     * Return the checkout information as array
     * @return array
     */
    protected function getCheckoutInfo()
    {
        if (!is_array($this->arrCheckoutInfo))
        {
            $arrCheckoutInfo = array();

            // Run trough all steps to collect checkout information
            foreach ($GLOBALS['ISO_CHECKOUT_STEPS'] as $step => $arrCallbacks)
            {
                foreach ($arrCallbacks as $callback)
                {
                    if ($callback[0] == 'ModuleIsotopeCheckout')
                    {
                        $arrInfo = $this->{$callback[1]}(true);
                    }
                    else
                    {
                        $this->import($callback[0]);
                        $arrInfo = $this->{$callback[0]}->{$callback[1]}($this, true);
                    }

                    if (is_array($arrInfo) && !empty($arrInfo))
                    {
                        $arrCheckoutInfo += $arrInfo;
                    }
                }
            }

            reset($arrCheckoutInfo);
            $arrCheckoutInfo[key($arrCheckoutInfo)]['class'] .= ' first';
            end($arrCheckoutInfo);
            $arrCheckoutInfo[key($arrCheckoutInfo)]['class'] .= ' last';

            $this->arrCheckoutInfo = $arrCheckoutInfo;
        }

        return $this->arrCheckoutInfo;
    }


    /**
     * Override parent addToUrl function. Use generateFrontendUrl if we want to remove all parameters.
     * @param string
     * @param boolean
     * @return string
     */
    public static function addToUrl($strRequest, $blnIgnoreParams=false)
    {
        if ($blnIgnoreParams)
        {
            global $objPage;

            // Support for auto_item parameter
            if ($GLOBALS['TL_CONFIG']['useAutoItem'])
            {
                $strRequest = str_replace('step=', '', $strRequest);
            }

            return \Controller::generateFrontendUrl($objPage->row(), '/' . str_replace(array('=', '&amp;', '&'), '/', $strRequest));
        }

        return parent::addToUrl($strRequest, $blnIgnoreParams);
    }
}
