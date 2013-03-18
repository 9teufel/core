<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2012 Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://www.isotopeecommerce.com
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 *
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 */


var Isotope =
{
    toggleAddressFields: function(el, id)
    {
        if (el.value == '0' && el.checked)
        {
            document.id(id).setStyle('display', 'block');
        }
        else
        {
            document.id(id).setStyle('display', 'none');
        }
    },

    /**
     * Display a "loading data" message
     * @param string
     */
    displayBox: function(message, btnClose)
    {
        var box = document.id('iso_ajaxBox');
        var overlay = document.id('iso_ajaxOverlay');

        if (!overlay)
        {
            overlay = new Element('div').setProperty('id', 'iso_ajaxOverlay').injectInside(document.id(document.body));
        }

        if (!box)
        {
            box = new Element('div').setProperty('id', 'iso_ajaxBox').injectInside(document.id(document.body));
        }

        if (btnClose)
        {
            overlay.addEvent('click', Isotope.hideBox);
            box.addClass('btnClose').addEvent('click', Isotope.hideBox);
        }

        var scroll = window.getScroll().y;
        if (Browser.Engine.trident && Browser.Engine.version < 5) { var sel = $$('select'); for (var i=0; i<sel.length; i++) { sel[i].setStyle('visibility', 'hidden'); } }

        overlay.setStyle('display', 'block');

        box.set('html', message);
        box.setStyle('display', 'block');
        box.setStyle('top', (scroll + 100) + 'px');
    },


    /**
     * Hide the "loading data" message
     */
    hideBox: function()
    {
        var box = document.id('iso_ajaxBox');
        var overlay = document.id('iso_ajaxOverlay');

        if (overlay)
        {
            overlay.setStyle('display', 'none').removeEvents('click');
        }

        if (box)
        {
            box.setStyle('display', 'none').removeEvents('click').removeClass('btnClose');
            if (Browser.Engine.trident && Browser.Engine.version < 5) { var sel = $$('select'); for (var i=0; i<sel.length; i++) { sel[i].setStyle('visibility', 'visible'); } }
        }
    },

    inlineGallery: function(el, elementId)
    {
        $$(('#'+elementId+'_mediumsize img')).set('src', el.href);

        $$(('#'+elementId+'_gallery div, #'+elementId+'_gallery img')).removeClass('active');

        el.addClass('active');
        el.getChildren().addClass('active');

        return false;
    }
};


var IsotopeProduct = new Class(
{
    Implements: Options,
    Binds: ['refresh'],
    options: {
        language: 'en',
        action: 'fmd',
        page: 0,
        loadMessage: 'Loading product data …'
    },

    initialize: function(ajaxid, product, formId, attributes, options)
    {
        this.setOptions(options);

        this.form = document.id(formId);

        if (this.form)
        {
            this.form.set('send',
            {
                url: ('ajax.php?action='+this.options.action+'&id='+ajaxid+'&language='+this.options.language+'&page='+this.options.page+'&product='+product),
                link: 'cancel',
                onRequest: function()
                {
                    Isotope.displayBox(this.options.loadMessage);
                }.bind(this),
                onSuccess: function(txt, xml)
                {
                    Isotope.hideBox();

                    var json = JSON.decode(txt);

                    // Update request token
                    REQUEST_TOKEN = json.token;
                    document.getElements('input[type="hidden"][name="REQUEST_TOKEN"]').set('value', json.token);

                    json.content.each( function(option)
                    {
                        var oldEl = document.id(option.id);
                        if (oldEl)
                        {
                            var newEl = null;
                            new Element('div').set('html', option.html).getElements('*').each( function(child) {
                                if (child.get('id') == option.id)
                                {
                                    newEl = child;
                                }
                            });

                            if (newEl)
                            {
                                if (newEl.hasClass('radio_container'))
                                {
                                    newEl.getElements('input.radio').each( function(option, index) {
                                        option.addEvent('click', this.refresh);
                                    }.bind(this));
                                }

                                newEl.cloneEvents(oldEl).replaces(oldEl);
                            }
                        }
                    }.bind(this));

                    // Update conditionalselect
                    window.fireEvent('ajaxready');
                    $$(('#'+formId+' p.error')).destroy();
                }.bind(this),
                onFailure: function()
                {
                    Isotope.hideBox();
                }
            });

            attributes.each( function(el,index)
            {
                el = document.id(el);
                if (el && el.hasClass('radio_container'))
                {
                    el.getElements('input.radio').each( function(option) {
                        option.addEvent('click', this.refresh);
                    }.bind(this));
                }
                else if(el)
                {
                    el.addEvent('change', this.refresh);
                }
            }.bind(this));
        }
    },

    refresh: function(event)
    {
        this.form.send();
    }
});

