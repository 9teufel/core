<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * TYPOlight Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  Winans Creative 2009, Intelligent Spark 2010, iserv.ch GmbH 2010
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */

class IsotopeRules extends Controller
{
	/**
	 * Current object instance (Singleton)
	 * @var object
	 */
	protected static $objInstance;
	
	/**
	 * Isotope object
	 * @var object
	 */
	protected $Isotope;
	
	/**
	 * Prevent cloning of the object (Singleton)
	 */
	final private function __clone() {}
	
	
	/**
	 * Prevent direct instantiation (Singleton)
	 */
	protected function __construct()
	{
		parent::__construct();	
		$this->import('Database');
		$this->import('FrontendUser','User');
		$this->import('Isotope');
	}
	
	/**
	 * Instantiate a database driver object and return it (Factory)
	 *
	 * @return object
	 */
	public static function getInstance()
	{
		if (!is_object(self::$objInstance))
		{
			self::$objInstance = new IsotopeRules();
		}

		return self::$objInstance;
	}
	
	
	/** 
	 * Returns a rule form if needed
	 * @access public
	 * @param object $objModule
	 * @return string
	 */
	public function getCouponForm($objModule)
	{		
		$arrProducts = $this->Isotope->Cart->getProducts();	
		
		$arrData = $this->getEligibleRules($arrProducts,'rules');	//returns a collection of rules and their respective products that are associated.
		
		if(!count($arrData))
			return '';
					
		if($this->Input->post('FORM_SUBMIT')=='iso_cart_rules')
		{			
			if($this->Input->post('code'))
			{
				$arrAppliedRules = $this->applyRules($arrData,$this->Input->post('code'));
				
				$this->saveRules($arrAppliedRules);
			}
		}
					
		//build template
		$objTemplate = new FrontendTemplate('iso_coupons');
		
		$objTemplate->action = $this->Environment->request;
		$objTemplate->formId = 'iso_cart_rules';
		$objTemplate->formSubmit = 'iso_cart_rules';
		$objTemplate->headline = $GLOBALS['TL_LANG']['ISO']['couponsHeadline'];
		$objTemplate->message = NULL;
		$objTemplate->inputLabel = $GLOBALS['TL_LANG']['ISO']['couponsInputLabel'];
		$objTemplate->sLabel = $GLOBALS['TL_LANG']['ISO']['couponsSubmitLabel'];
		$objTemplate->error = ($blnResult ? $GLOBALS['TL_LANG']['ERR']['invalidRule'] : NULL);
	
		return $objTemplate->parse();
	}
	
	
	/** 
	 * get any rule pricing
	 */
	public function getRules($arrObjects = array(), $objSource = NULL)
	{
		if(!count($arrObjects))
			return $arrObjects;
						
		$arrReturn = array();
		$arrData = array();
				
		if($objSource instanceof IsotopeProductCollection)	//@TODO Make space for additional custom class rule eligibility hooking
		{
			$arrObjects[] = $objSource;
		}

		$arrData = $this->getEligibleRules($arrObjects, 'rules');
			
		if(!count($arrData))
			return $arrObjects;
			
		return $this->applyRules($arrObjects,$arrData);
		
	}
	
	
	/** 
	 * Upon adding to cart, we need to somehow store the rule so it can be cached & recalled.
	 * @access public
	 * @param object $objProduct
	 * @param object $objModule
	 */
	/*public function addToCart($objProduct, $objModule=null)
	{
		$arrProducts[] = $objProduct;	//Get the current product
		$arrData = $this->getEligibleRules($arrProducts, 'rules');
		
		$arrAppliedRules = $this->applyRules($arrData);
		
		$this->saveRules($arrAppliedRules);	//session save by default	
	}*/
	
	/** 
	 * check eligibility for products
	 * @access protected
	 * @param array $arrProducts
	 * @param string $strQueryMode
	 * @return array $arrReturn
	 */ 
	protected function getEligibleRules($arrObjects, $strQueryMode = '')
	{							
		if(!count($arrObjects))
			return '';
		
		$intToday = time();
		
		if(FE_USER_LOGGED_IN)
		{
			$arrCustomer['members'] 		= $this->User->id;
			$arrCustomer['countries'] 		= $this->User->country;
			$arrCustomer['subdivisions'] 	= $this->User->state;
			$arrCustomer['groups']			= deserialize($this->User->groups, true);
		}
		else
		{
			$arrCustomer['members'] = 0;
			$arrCustomer['groups'] = 0;
			$arrCustomer['countries'] = '';
			$arrCustomer['subdivisions'] = '';
		}
	
		switch($strQueryMode)
		{
			case 'coupons':
				$strRulesClause = " AND enableCode='1'";
				break;
			case 'rules':
				$strRulesClause = " AND enableCode=''";
			default:
				break;
		}
									
		//determine eligibility for the current shopper. //restrictions either null or not matching
		$objRules = $this->Database->executeUncached("SELECT c.*, (SELECT COUNT(u.id) AS ruleUses FROM tl_iso_rule_usage u WHERE u.pid=c.id) AS uses FROM tl_iso_rule c WHERE c.enabled='1'".$strRulesClause);
	
		if(!$objRules->numRows)
			return '';
						
		$arrRuleIds = array();
		$arrMemberUsesByRule = array();
		
		$arrRuleIds = $objRules->fetchEach('id');
		
		$arrRules = $objRules->fetchAllAssoc();
		
		$strRuleIds = implode(',', $arrRuleIds);
		
		//gather all usage data for the rules we have returned.. if a rule is for non-members, then this query by default is checking usage in terms of global use  		//of the rule rather that per user as we haven't a way to verify usage for a non-member.  
		if(FE_USER_LOGGED_IN)
		{
			$objMemberUses = $this->Database->executeUncached("SELECT *, COUNT(id) AS customerUses FROM tl_iso_rule_usage WHERE pid IN($strRuleIds) AND member_id={$this->User->id}");
			
			if($objMemberUses->numRows)		
			{
				while($objMemberUses->next());
				{
					$arrMemberUsesByRule[$objMemberUses->pid] = $objMemberUses->row();
				}
			}
		}
				
		foreach($arrObjects as $i => $object)
		{
			if($object instanceof IsotopeProduct)
			{
					$arrObject['pages'] = $object->pages;
					$arrObject['productTypes'] = $object->type;
					$arrObject['products'] = $object->id;
					$intObjectId = $object->id; //necessary to check the usage table by product collection class id (for example, cart id)
					$object->coupons = array();	//@TODO: get rules for this item from the container or else reinstate the rules field for items.
			}
			elseif($object instanceof IsotopeProductCollection)
			{
					$intObjectId=$object->id; //necessary to check the usage table by product collection class id (for example, cart id)
					$arrObject = array(); //this is only necessary for product-level rules.
			}
			else
			{
					//@TODO: HOOK THIS for other unanticipated classes that don't fit the two we provide for?
					break;
			}
			
			$arrCustomerMatrix = array_merge($arrCustomer, $arrObject);		
					
			foreach($arrRules as $row)
			{				
				//Check existing usage
				if($row['uses'])
				{
					$arrUses = deserialize($row['numUses'], true);
			
					if(count($arrUses) && $arrUses['value']>0)
					{
						switch($arrUses['unit'])
						{
							case 'customer':
								if(FE_USER_LOGGED_IN)
								{																		
									//if the number of customer uses exceeds this rule in total, or the current product has already had the rule applied to it...					
									if($arrUses['value'] <= $arrMemberUsesByRule[$row['id']]['customerUses'] || $intObjectId==$arrMemberUsesByRule[$row['id']]['object_id'])
									{	
										break(2);	//don't allow
									}
								}							
								break;
							case 'store':
								if($arrUses['value'] <= $row['uses'])
								{
									break(2);	//don't allow
								}							
								break;	
							default:
								break;				
						}
					}
				}
				
				if($row['collectionTypeRestrictions'])
				{
					$arrCollectionTypes = deserialize($row['collectionTypes']);
					
					if(!$object instanceof IsotopeProductCollection || !in_array(get_class($object), $arrCollectionTypes))
						break;
				}
				
				if($row['dateRestrictions'])
				{
					if($row['startDate'] > time() || $row['endDate'] < time())
						break;
				}
				
				//check time, will be verified again later.	fix
				
				if($row['timeRestrictions'])
				{
					if($row['startTime'] > time() || $row['endTime'] < time())
						break;				
				}
				
				//exclusion of other rules, all or certain ones
				switch($row['ruleRestrictions'])
				{
					case 'all':
						if(count($object->coupons))
							break(2);
					case 'rules':
						$arrExcludedRules = deserialize($row['rules'], true);	//get specific rules for exclusion check
						if(count($arrRules) && array_intersect($object->coupons, $arrExcludedRules))
							break(2);
					default:
						break;
				}
				
								
				//Usage didn't stop us, let's further check for member restrictions
				switch($row['memberRestrictions'])
				{
					case 'groups':
					case 'members':
						if($row[$row['memberRestrictions']])
							$arrRestrictions[$row['memberRestrictions']] = deserialize($row[$row['memberRestrictions']]);
						break;
					default:
						break;			
				}
				
				switch($row['type'])
				{
					case 'cart_item':
						if($row['minItemQuantity'] && $row['minItemQuantity'] > $object->quantity_requested)
							break(2);
													
						switch($row['productRestrictions'])
						{
							case 'productTypes':
							case 'pages':
							case 'products':
								if($row[$row['productRestrictions']])
									$arrRestrictions[$row['productRestrictions']] = deserialize($row[$row['productRestrictions']]);
								break;				
							default:
								break;			
						}
						
					case 'cart':
						if($row['minSubTotal']>0 && $object->subTotal > $row['minSubTotal'])
							break(2);
						
						if($row['minCartQuantity']>0 && $object->totalQuantity > $row['minCartQuantity'])
							break(2);
						break;
					default:
						//@TODO: Hook for additional types of rule-eligible objects
						break;
				}
											
				if(count($arrRestrictions))
				{														
					$blnLoopBreak = false;									
					foreach($arrRestrictions as $k=>$v) //check each field in the rule row
					{											
						if(is_array($arrCustomerMatrix[$k]) && is_array($v))	//mismatch! break to next row.
						{										
							$cRow[$k] = array_map('strval', $arrCustomerMatrix[$k]);
							$v = array_map('strval', $v);
															
							if(!count(array_intersect($arrCustomerMatrix[$k], $v)))																				
								$blnLoopBreak = true;
						}
						elseif(!in_array($arrCustomerMatrix[$k], $v))
						{
							$blnLoopBreak = true;
						}									
						
						if($blnLoopBreak)
							break(2);
					}
					
					$arrReturn[get_class($object)][$intObjectId][] = $row;
				}
			} 	//end rules loop
			
		}	//end products loop

		if(!count($arrReturn))
			return array();	
	
		//return an array of eligible rules to each item in the cart.
		return $arrReturn;
	}
	
	
	/** 
	 * Match ruleCodes entered against eligible rules in array
	 * 
	 * @access protected
	 * @param string $strCodes
	 * @param array $arrData
	 * @return boolean
	 * @TODO: include an option for caching rules that are applied to items in the cart
	 */
	protected function applyRules($arrObjects,$arrData,$strCodes='')
	{		
		$arrUsedCodes = array();
		$arrCodes = array();
		$arrAppliedRules = array();
					
		if($strCodes)
			$arrCodes = explode(',', $strCodes);

		$arrUsedCodes = array();

		foreach($arrObjects as $i=>$object)
		{
			$arrRules = array();

			$intObjectId = $object->id;	
						
			if(!count($arrData[get_class($object)][$intObjectId]))
				continue;
			
			foreach($arrData[get_class($object)][$intObjectId] as $rule)
			{		
				
				if(count($arrCodes) && (!in_array($rule['code'], $arrCodes) || in_array($rule['id'], $arrAppliedRules)))
				{
							continue;
				}
				elseif(count($arrCodes))
				{
					//add to used codes as it will be used now.
					$arrAppliedRules[] = $rule['id'];
				}
				
				$blnPercentage = strpos($rule['discount'], '%');
					
				switch($rule['type'])
				{ 
					case 'product':					
						if($blnPercentage)
						{	
							$intValue = (float)rtrim($rule['discount'], '%') / 100;	
							$fltChange = ($object->price * $intValue);
						}
						else
						{
							$fltChange = (float)$rule['discount'];
						}										
						break;
					case 'product_collection':
						if($blnPercentage)
						{	
							$fltValue = (float)rtrim($rule['discount'], '%') / 100;	
							
							$fltChange = $object->subTotal * $fltValue;
						}
						else
						{
							$fltChange = (float)$rule['discount'];
						}																
						break;
					default:
						//@TODO: Hook for other types of coupons
						continue;
				}
				
				$arrRules[] = array
				(
					'label'			=> $rule['title'],
					'price'			=> ($blnPercentage ? $rule['discount'] : $this->Isotope->formatPriceWithCurrency($rule['discount'])),
					'total_price'	=> $this->Isotope->formatPriceWithCurrency($fltChange,false)
				);
								
			}	//end rules for this particular object
			
			if(count($arrRules))
				$object->prices = $arrRules;
				
			$arrFinalObjects[] = $object;
		
		}	//end objects
			
		return $arrFinalObjects;
	}
	
	/** 
	 * Save the currently used rules either to the session or else to the usage table in the case of confirmed rules.
	 * @access private
	 * @param array $arrData
	 * @param string $strContainer
	 */
	private function saveRules($arrData = array(), $arrObjects, $strContainer = '')
	{
		if(!count($arrData))
			return;
		
		switch($strContainer)
		{
			case 'table':
				foreach($arrProducts as $i=>$o
				foreach($arrData as $row)
				{
					//update the usage table 
					$arrSet['tstamp'] 		= time();
					$arrSet['pid'] 			= $row['rule']['id'];
					$arrSet['member_id'] 	= (FE_USER_LOGGED_IN ? $this->User->id : 0);
					//$arrSet['object_type']	= 
					$arrSet['object_id'] 	= ($row['object']['id'];
					
					$this->Database->prepare("INSERT INTO tl_iso_rule_usage %s")
								   ->set($arrSet)
								   ->execute();
				}
				break;
			default:
				foreach($arrData as $rule)
				{
					$_SESSION['CHECKOUT_DATA']['rules'][] = $rule;
				}
				break;
		}
	}
	
	/** 
	 * Verify that our rules are still in fact, valid just before payment is completed.
	 * @access public
	 * @param object $objModule
	 */
	public function verifyRules()
	{
		$arrProducts = $this->Isotope->Cart->getProducts();
	
		$arrData = $this->getEligibleRules($arrProducts);	//returns a collection of rules and their respective products that are associated.
		
		if(count($arrData))
			$this->saveRules($arrData, 'table');
	}
	
	
	/** 
	 * Hook-callback for rules @TODO - determine if needed
	 * 
	 * @access public
	 * @param array
	 * @return array
	 */
	public function getRulesSurcharges($arrSurcharges)
	{
		$objRules = $this->Database->query("SELECT rules FROM tl_iso_cart WHERE id={$this->id}");
		
		if(!$objRules->numRows)
			return $arrSurcharges;
		
		$arrRules = deserialize($objRules->rules, true);
		
		foreach($arrRules as $rule)
		{
			$arrSurcharges[] = array
			(
				'label'			=> $rule['title'],
				'price'			=> $rule['price'],
				'total_price'	=> $rule['total_price'],
				'tax_class'		=> 0,
				'add_tax'		=> false,
			);
		}
						
		return $arrSurcharges;
	}
}