/**
 * AI Shopping Assistant — Chat UI
 * Initialized via text/x-magento-init on #ai-chat-page
 */
define(['jquery', 'Magento_Customer/js/customer-data'], function ($, customerData) {
    'use strict';

    return function (config, element) {
        var $page    = $(element);
        var name     = $page.data('customer-name') || '';
        var formKey  = $page.data('form-key');
        var urls = {
            chat:           $page.data('chat-url'),
            productInfo:    $page.data('product-info-url'),
            productOptions: $page.data('product-options-url'),
            addToCart:      $page.data('add-to-cart-url'),
            addresses:      $page.data('addresses-url'),
            setAddress:         $page.data('set-address-url'),
            setShippingMethod:  $page.data('set-shipping-method-url'),
            removeItem:         $page.data('remove-item-url'),
            checkout:           $page.data('checkout-url')
        };

        var $msgs    = $('#ai-chat-messages');
        var $form    = $('#ai-chat-form');
        var $input   = $('#ai-chat-input');
        var $sendBtn = $('#ai-chat-send');

        // State: idle | searching | products_shown | options_shown | post_cart | cart_view | address_selection | shipping_selection
        var state = 'idle';

        // ── Greeting ──────────────────────────────────────────────────────────

        function init() {
            var h = new Date().getHours();
            var sal = h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
            var nameStr = name ? (', ' + name) : '';
            addBotMsg(sal + nameStr + '! How can I help you today?');
        }

        // ── Message helpers ───────────────────────────────────────────────────

        function addBotMsg(text) {
            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--bot">')
                    .append($('<div class="ai-chat-bubble">').text(text))
            );
            scrollBottom();
        }

        function addUserMsg(text) {
            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--user">')
                    .append($('<div class="ai-chat-bubble">').text(text))
            );
            scrollBottom();
        }

        function addTyping() {
            $msgs.append(
                $('<div id="ai-typing" class="ai-chat-msg ai-chat-msg--bot ai-chat-typing">')
                    .append(
                        $('<div class="ai-chat-bubble">')
                            .append('<span class="ai-chat-dot"></span>')
                            .append('<span class="ai-chat-dot"></span>')
                            .append('<span class="ai-chat-dot"></span>')
                    )
            );
            scrollBottom();
        }

        function removeTyping() { $('#ai-typing').remove(); }

        function scrollBottom() { $msgs.scrollTop($msgs[0].scrollHeight); }

        function lock(yes) {
            $sendBtn.prop('disabled', yes);
            $input.prop('disabled', yes);
        }

        // ── Intent detection ──────────────────────────────────────────────────

        function isCartIntent(query) {
            return /\b(cart|basket|trolley)\b|my items?|\bshow\b.{0,20}\bitems?\b|\bview\b.{0,20}\bitems?\b|what.{0,20}(added|in (my|the) (cart|bag))/i.test(query);
        }

        // ── Form submit ───────────────────────────────────────────────────────

        $form.on('submit', function (e) {
            e.preventDefault();
            var query = $.trim($input.val());
            if (!query) return;

            // Cart intent is handled from any state — no AI round-trip needed
            if (isCartIntent(query)) {
                addUserMsg(query);
                $input.val('').css('height', 'auto');
                showCartItems();
                return;
            }

            if (state !== 'idle') return;

            addUserMsg(query);
            $input.val('').css('height', 'auto');
            lock(true);
            state = 'searching';
            addTyping();

            // Call the existing anonymous REST endpoint directly
            $.ajax({
                url:         urls.chat,
                type:        'POST',
                contentType: 'application/json',
                data:        JSON.stringify({ message: { query: query } }),
                success: function (resp) {
                    removeTyping();
                    var data = typeof resp === 'string' ? JSON.parse(resp) : resp;
                    if (data.response) addBotMsg(data.response);
                    if (data.products && data.products.length) {
                        fetchProductDetails(data.products);
                    } else {
                        lock(false);
                        state = 'idle';
                    }
                },
                error: function () {
                    removeTyping();
                    addBotMsg("Sorry, I'm having trouble right now. Please try again.");
                    lock(false);
                    state = 'idle';
                }
            });
        });

        // Enter = send, Shift+Enter = newline
        $input.on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                $form.trigger('submit');
            }
        });

        // Auto-expand textarea
        $input.on('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 140) + 'px';
        });

        // ── Product cards ─────────────────────────────────────────────────────

        function fetchProductDetails(products) {
            var skus = products.map(function (p) { return p.sku; });
            $.ajax({
                url:         urls.productInfo,
                type:        'POST',
                contentType: 'application/json',
                data:        JSON.stringify({ skus: skus, form_key: formKey }),
                success: function (resp) {
                    var data = typeof resp === 'string' ? JSON.parse(resp) : resp;
                    var enriched = data.products || [];
                    if (enriched.length) {
                        showProductCards(enriched);
                        state = 'products_shown';
                    } else {
                        addBotMsg("I found results but couldn't load product details. Please try again.");
                        state = 'idle';
                    }
                    lock(false);
                },
                error: function () {
                    addBotMsg("I found results but couldn't load product details. Please try again.");
                    lock(false);
                    state = 'idle';
                }
            });
        }

        function showProductCards(products) {
            var $cards = $('<div class="ai-chat-products">');
            products.forEach(function (p) {
                var $card = $('<div class="ai-product-card">')
                    .attr('data-sku', p.sku)
                    .attr('data-type', p.type_id || 'simple');

                if (p.image_url) {
                    $card.append(
                        $('<img class="ai-product-card__image">').attr('src', p.image_url).attr('alt', p.name)
                    );
                } else {
                    $card.append($('<div class="ai-product-card__image--placeholder">').text('[ ]'));
                }
                $card.append($('<div class="ai-product-card__name">').text(p.name));
                $card.append($('<div class="ai-product-card__price">').text(p.price_formatted || ''));
                $card.on('click', function () { onProductClick(p); });
                $cards.append($card);
            });
            $msgs.append($('<div class="ai-chat-msg ai-chat-msg--bot">').append($cards));
            scrollBottom();
        }

        // ── Product click ─────────────────────────────────────────────────────

        function onProductClick(product) {
            if (state !== 'products_shown') return;

            if (product.type_id === 'configurable') {
                lock(true);
                addTyping();
                $.ajax({
                    url:         urls.productOptions,
                    type:        'POST',
                    contentType: 'application/json',
                    data:        JSON.stringify({ sku: product.sku, form_key: formKey }),
                    success: function (resp) {
                        removeTyping();
                        var data = typeof resp === 'string' ? JSON.parse(resp) : resp;
                        showOptionsForm(data.options || [], product);
                        state = 'options_shown';
                        lock(false);
                    },
                    error: function () {
                        removeTyping();
                        addBotMsg("Couldn't load options for this product. Please try another.");
                        lock(false);
                    }
                });
            } else {
                doAddToCart(product.sku, {});
            }
        }

        // ── Options selector ──────────────────────────────────────────────────

        function showOptionsForm(options, product) {
            addBotMsg('Please select options for "' + product.name + '":');
            var selected = {};
            var $wrap = $('<div class="ai-options-form">');

            options.forEach(function (opt) {
                var $group = $('<div class="ai-option-group">');
                $group.append($('<label>').text(opt.label));
                var $choices = $('<div class="ai-option-choices">');
                opt.values.forEach(function (val) {
                    $('<button type="button" class="ai-option-btn">')
                        .text(val.label)
                        .on('click', function () {
                            $choices.find('.ai-option-btn').removeClass('selected');
                            $(this).addClass('selected');
                            selected[opt.attribute_id] = val.value_id;
                        })
                        .appendTo($choices);
                });
                $group.append($choices);
                $wrap.append($group);
            });

            $('<button type="button" class="action primary ai-options-confirm">Add to Cart</button>')
                .on('click', function () {
                    if (Object.keys(selected).length < options.length) {
                        addBotMsg('Please select all options before adding to cart.');
                        return;
                    }
                    doAddToCart(product.sku, selected);
                })
                .appendTo($wrap);

            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--bot">')
                    .append($('<div class="ai-chat-bubble">').append($wrap))
            );
            scrollBottom();
        }

        // ── Add to cart ───────────────────────────────────────────────────────

        function doAddToCart(sku, superAttribute) {
            lock(true);
            addTyping();
            $.ajax({
                url:         urls.addToCart,
                type:        'POST',
                contentType: 'application/json',
                data:        JSON.stringify({ sku: sku, super_attribute: superAttribute, qty: 1, form_key: formKey }),
                success: function () {
                    removeTyping();
                    // Refresh mini cart counter in header
                    customerData.invalidate(['cart']);
                    customerData.reload(['cart'], true);
                    addBotMsg('Added to your cart!');
                    showPostCartChoices();
                    state = 'post_cart';
                    lock(false);
                },
                error: function () {
                    removeTyping();
                    addBotMsg("Couldn't add that to your cart. Please try again.");
                    lock(false);
                    state = 'products_shown';
                }
            });
        }

        // ── Post-cart choices ─────────────────────────────────────────────────

        function showPostCartChoices() {
            addBotMsg('What would you like to do next?');
            var $choices = $('<div class="ai-chat-choices">');

            $choices.append(
                $('<label class="ai-choice-label">')
                    .append($('<input type="radio" name="post_cart" value="more">'))
                    .append(' Add more products')
            );
            $choices.append(
                $('<label class="ai-choice-label">')
                    .append($('<input type="radio" name="post_cart" value="checkout">'))
                    .append(' Select an address')
            );
            $choices.append(
                $('<label class="ai-choice-label">')
                    .append($('<input type="radio" name="post_cart" value="cart">'))
                    .append(' Show my cart')
            );

            $choices.on('change', 'input[name="post_cart"]', function () {
                var choice = $(this).val();
                $choices.find('input').prop('disabled', true);
                if (choice === 'more') {
                    addBotMsg('Sure! What else are you looking for?');
                    state = 'idle';
                } else if (choice === 'cart') {
                    showCartItems();
                } else {
                    loadAddresses();
                }
            });

            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--bot">')
                    .append($('<div class="ai-chat-bubble">').append($choices))
            );
            scrollBottom();
        }

        // ── Cart view ─────────────────────────────────────────────────────────

        function showCartItems() {
            var cart  = customerData.get('cart')();
            var items = cart.items || [];

            if (!items.length) {
                addBotMsg('Your cart is empty.');
                state = 'idle';
                return;
            }

            addBotMsg('Here is what\'s in your cart:');
            var $list = $('<div class="ai-cart-list">');

            items.forEach(function (item) {
                var $row = $('<div class="ai-cart-item">')
                    .attr('data-item-id', item.item_id);

                if (item.product_image && item.product_image.src) {
                    $('<img class="ai-cart-item__img">')
                        .attr({ src: item.product_image.src, alt: item.product_image.alt || item.product_name })
                        .appendTo($row);
                }

                var $info = $('<div class="ai-cart-item__info">').appendTo($row);

                $('<div class="ai-cart-item__name">').text(item.product_name).appendTo($info);

                var options = item.options || [];
                if (options.length) {
                    var $opts = $('<div class="ai-cart-item__options">');
                    options.forEach(function (opt) {
                        $('<span class="ai-cart-item__option">')
                            .text(opt.label + ': ' + opt.value)
                            .appendTo($opts);
                    });
                    $opts.appendTo($info);
                }

                $('<div class="ai-cart-item__meta">')
                    .append(document.createTextNode('Qty: ' + item.qty + '  ·  '))
                    .append($('<span>').html(item.product_price))
                    .appendTo($info);

                $('<button type="button" class="ai-cart-item__remove" title="Remove">')
                    .html('&#x2715;')
                    .on('click', function () {
                        removeCartItem(item.item_id, $row, $list);
                    })
                    .appendTo($row);

                $row.appendTo($list);
            });

            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--bot">')
                    .append($('<div class="ai-chat-bubble">').append($list))
            );

            showCartActions();
            state = 'cart_view';
            scrollBottom();
        }

        function showCartActions() {
            var $choices = $('<div class="ai-chat-choices">');

            $choices.append(
                $('<label class="ai-choice-label">')
                    .append($('<input type="radio" name="cart_action" value="more">'))
                    .append(' Add more products')
            );
            $choices.append(
                $('<label class="ai-choice-label">')
                    .append($('<input type="radio" name="cart_action" value="checkout">'))
                    .append(' Select an address')
            );

            $choices.on('change', 'input[name="cart_action"]', function () {
                $choices.find('input').prop('disabled', true);
                if ($(this).val() === 'more') {
                    addBotMsg('Sure! What else are you looking for?');
                    state = 'idle';
                } else {
                    loadAddresses();
                }
            });

            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--bot">')
                    .append($('<div class="ai-chat-bubble">').append($choices))
            );
        }

        function removeCartItem(itemId, $row, $list) {
            $row.addClass('ai-cart-item--removing');
            $.ajax({
                url:  urls.removeItem,
                type: 'POST',
                data: { item_id: itemId, form_key: formKey },
                dataType: 'json',
                success: function () {
                    $row.remove();
                    customerData.invalidate(['cart']);
                    customerData.reload(['cart'], true);
                    if (!$list.children().length) {
                        $list.closest('.ai-chat-bubble').text('Your cart is now empty.');
                        state = 'idle';
                    }
                },
                error: function () {
                    $row.removeClass('ai-cart-item--removing');
                    addBotMsg("Couldn't remove that item. Please try again.");
                }
            });
        }

        // ── Address selection ─────────────────────────────────────────────────

        function loadAddresses() {
            lock(true);
            addTyping();
            $.ajax({
                url:         urls.addresses,
                type:        'POST',
                contentType: 'application/json',
                data:        JSON.stringify({ form_key: formKey }),
                success: function (resp) {
                    removeTyping();
                    var data    = typeof resp === 'string' ? JSON.parse(resp) : resp;
                    var addrs   = data.addresses || [];
                    if (!addrs.length) {
                        addBotMsg("You don't have any saved addresses.");
                        $msgs.append(
                            $('<div class="ai-chat-msg ai-chat-msg--bot">')
                                .append($('<div class="ai-chat-bubble">')
                                    .append($('<a class="action primary">').attr('href', urls.checkout).text('Go to checkout page to add address')))
                        );
                    } else {
                        showAddressPicker(addrs);
                    }
                    state = 'address_selection';
                    lock(false);
                    scrollBottom();
                },
                error: function () {
                    removeTyping();
                    addBotMsg("Couldn't load your addresses. Please proceed to checkout directly.");
                    $msgs.append(
                        $('<div class="ai-chat-msg ai-chat-msg--bot">')
                            .append($('<div class="ai-chat-bubble">')
                                .append($('<a class="action primary">').attr('href', urls.checkout).text('Go to Checkout')))
                    );
                    lock(false);
                    scrollBottom();
                }
            });
        }

        function showAddressPicker(addresses) {
            addBotMsg('Please select a shipping address:');
            var $choices = $('<div class="ai-chat-choices">');
            addresses.forEach(function (addr) {
                var parts = [addr.firstname, addr.lastname, addr.street, addr.city, addr.region, addr.postcode]
                    .filter(Boolean).join(', ');
                $('<label class="ai-choice-label">')
                    .append($('<input type="radio" name="addr_pick">').val(addr.id))
                    .append(' ' + parts)
                    .appendTo($choices);
            });
            $('<button type="button" class="action primary ai-choices-confirm">Select shipping method</button>')
                .on('click', function () {
                    var addrId = $choices.find('input[name="addr_pick"]:checked').val();
                    if (!addrId) return;
                    doSetAddress(addrId);
                })
                .appendTo($choices);
            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--bot">')
                    .append($('<div class="ai-chat-bubble">').append($choices))
            );
            scrollBottom();
        }

        // ── Checkout ──────────────────────────────────────────────────────────

        function doSetAddress(addressId) {
            lock(true);
            addTyping();
            $.ajax({
                url:         urls.setAddress,
                type:        'POST',
                contentType: 'application/json',
                data:        JSON.stringify({ address_id: addressId, form_key: formKey }),
                success: function (resp) {
                    removeTyping();
                    var data    = typeof resp === 'string' ? JSON.parse(resp) : resp;
                    var methods = data.shipping_methods || [];
                    if (methods.length) {
                        showShippingMethodPicker(methods);
                        state = 'shipping_selection';
                    } else {
                        showCheckoutButton();
                    }
                    lock(false);
                },
                error: function () {
                    removeTyping();
                    addBotMsg("Couldn't set your address. Please proceed to checkout directly.");
                    showCheckoutButton();
                    lock(false);
                }
            });
        }

        function showShippingMethodPicker(methods) {
            addBotMsg('Please select a shipping method:');
            var $choices = $('<div class="ai-chat-choices">');

            methods.forEach(function (m) {
                var label = m.carrier_title + ' — ' + m.method_title + ' (' + m.price_formatted + ')';
                $('<label class="ai-choice-label">')
                    .append($('<input type="radio" name="ship_method">').val(m.code))
                    .append(' ' + label)
                    .appendTo($choices);
            });

            $('<button type="button" class="action primary ai-choices-confirm">Confirm Shipping</button>')
                .on('click', function () {
                    var code = $choices.find('input[name="ship_method"]:checked').val();
                    if (!code) return;
                    doSetShippingMethod(code);
                })
                .appendTo($choices);

            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--bot">')
                    .append($('<div class="ai-chat-bubble">').append($choices))
            );
            scrollBottom();
        }

        function doSetShippingMethod(methodCode) {
            lock(true);
            addTyping();
            $.ajax({
                url:         urls.setShippingMethod,
                type:        'POST',
                contentType: 'application/json',
                data:        JSON.stringify({ method_code: methodCode, form_key: formKey }),
                complete: function () {
                    removeTyping();
                    showCheckoutButton();
                    lock(false);
                }
            });
        }

        function showCheckoutButton() {
            addBotMsg('Great! Your order is ready.');
            $msgs.append(
                $('<div class="ai-chat-msg ai-chat-msg--bot">')
                    .append($('<div class="ai-chat-bubble">')
                        .append($('<a class="action primary">').attr('href', urls.checkout).text('Proceed to Checkout')))
            );
            scrollBottom();
        }

        // ── Boot ──────────────────────────────────────────────────────────────
        init();
    };
});
