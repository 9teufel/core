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

namespace Isotope\Model\Payment;

use Isotope\Isotope;
use Isotope\Interfaces\IsotopePayment;
use Isotope\Model\Payment;
use Isotope\Model\ProductCollection\Order;


/**
 * Class Datatrans
 *
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas@schempp.ch>
 * @author     Leo Unglaub <leo@leo-unglaub.net>
 */
class Datatrans extends Payment implements IsotopePayment
{

    /**
     * Perform server to server data check
     */
    public function processPostSale()
    {
        // Verify payment status
        if (\Input::post('status') != 'success')
        {
            $this->log('Payment for order ID "' . \Input::post('refno') . '" failed.', __METHOD__, TL_ERROR);

            return false;
        }

        if (($objOrder = Order::findByPk(\Input::post('refno'))) === null)
        {
            $this->log('Order ID "' . \Input::post('refno') . '" not found', __METHOD__, TL_ERROR);

            return false;
        }

        // Validate HMAC sign
        if (\Input::post('sign2') != hash_hmac('md5', $this->datatrans_id.\Input::post('amount').\Input::post('currency').\Input::post('uppTransactionId'), $this->datatrans_sign))
        {
            $this->log('Invalid HMAC signature for Order ID ' . \Input::post('refno'), __METHOD__, TL_ERROR);

            return false;
        }

        // For maximum security, also validate individual parameters
        if (!$this->validateParameters(array
        (
            'refno'        => $objOrder->id,
            'currency'    => $objOrder->currency,
            'amount'    => round($objOrder->grandTotal * 100),
            'reqtype'    => ($this->trans_type == 'auth' ? 'NOA' : 'CAA'),
        )))
        {
            return false;
        }

        $objOrder->checkout();
        $objOrder->date_payed = time();
        $objOrder->save();
    }


    /**
     * Validate post parameters and complete order
     * @return bool
     */
    public function processPayment()
    {
        if (($objOrder = Order::findOneBy('source_collection_id', Isotope::getCart()->id)) === null)
        {
            return false;
        }

        if ($objOrder->date_payed > 0 && $objOrder->date_payed <= time())
        {
            unset($_SESSION['PAYMENT_TIMEOUT']);

            return true;
        }

        if (!isset($_SESSION['PAYMENT_TIMEOUT']))
        {
            $_SESSION['PAYMENT_TIMEOUT'] = 60;
        }
        else
        {
            $_SESSION['PAYMENT_TIMEOUT'] = $_SESSION['PAYMENT_TIMEOUT'] - 5;
        }

        if ($_SESSION['PAYMENT_TIMEOUT'] === 0)
        {
            global $objPage;
            $this->log('Payment could not be processed.', __METHOD__, TL_ERROR);
            $this->redirect($this->generateFrontendUrl($objPage->row(), '/step/failed'));
        }

        // Reload page every 5 seconds and check if payment was successful
        $GLOBALS['TL_HEAD'][] = '<meta http-equiv="refresh" content="5,' . \Environment::get('base') . \Environment::get('request') . '">';

        $objTemplate = new \Isotope\Template('mod_message');
        $objTemplate->type = 'processing';
        $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['payment_processing'];

        return $objTemplate->parse();
    }


    /**
     * Generate the submit form for datatrans and if javascript is enabled redirect automaticly
     * @return string
     */
    public function checkoutForm()
    {
        $objOrder = new Order();

        if (($objOrder = Order::findOneBy('source_collection_id', Isotope::getCart()->id)) === null)
        {
            $this->redirect($this->addToUrl('step=failed', true));
        }

        $objAddress = Isotope::getCart()->getBillingAddress();

        $arrParams = array
        (
            'merchantId'            => $this->datatrans_id,
            'amount'                => round(Isotope::getCart()->grandTotal * 100),
            'currency'                => Isotope::getConfig()->currency,
            'refno'                    => $objOrder->id,
            'language'                => $GLOBALS['TL_LANGUAGE'],
            'reqtype'                => ($this->trans_type == 'auth' ? 'NOA' : 'CAA'),
            'uppCustomerDetails'    => 'yes',
            'uppCustomerTitle'      => $objAddress->salutation,
            'uppCustomerFirstName'  => $objAddress->firstname,
            'uppCustomerLastName'   => $objAddress->lastname,
            'uppCustomerStreet'     => $objAddress->street_1,
            'uppCustomerStreet2'    => $objAddress->street_2,
            'uppCustomerCity'       => $objAddress->city,
            'uppCustomerCountry'    => $objAddress->country,
            'uppCustomerZipCode'    => $objAddress->postal,
            'uppCustomerPhone'      => $objAddress->phone,
            'uppCustomerEmail'      => $objAddress->email,
            'successUrl'            => ampersand(\Environment::get('base') . $this->addToUrl('step=complete', true)),
            'errorUrl'                => ampersand(\Environment::get('base') . $this->addToUrl('step=failed', true)),
            'cancelUrl'                => ampersand(\Environment::get('base') . $this->addToUrl('step=failed', true)),
            'mod'                    => 'pay',
            'id'                    => $this->id,
        );

        // Security signature (see Security Level 2)
        $arrParams['sign'] = hash_hmac('md5', $arrParams['merchantId'].$arrParams['amount'].$arrParams['currency'].$arrParams['refno'], $this->datatrans_sign);

        $objTemplate = new \Isotope\Template('iso_payment_datatrans');
        $objTemplate->id = $this->id;
        $objTemplate->action = ('https://' . ($this->debug ? 'pilot' : 'payment') . '.datatrans.biz/upp/jsp/upStart.jsp');
        $objTemplate->params = $arrParams;
        $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][0];
        $objTemplate->message = $GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][1];
        $objTemplate->slabel = specialchars($GLOBALS['TL_LANG']['MSC']['pay_with_redirect'][2]);

        return $objTemplate->parse();
    }


    /**
     * Validate array of post parameter agains required values
     * @param array
     * @return boolean
     */
    private function validateParameters(array $arrData)
    {
        foreach ($arrData as $key => $value)
        {
            if (\Input::post($key) != $value)
            {
                $this->log('Wrong data for parameter "' . $key . '" (Order ID "' . \Input::post('refno') . ').', __METHOD__, TL_ERROR);

                return false;
            }
        }

        return true;
    }
}
