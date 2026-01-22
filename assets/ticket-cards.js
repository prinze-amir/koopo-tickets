(function ($) {
  'use strict';

  function openModal($modal) {
    $modal.addClass('is-open');
    $('body').addClass('koopo-ticket--modal-open');
    resetModal($modal);
  }

  function closeModal($modal) {
    $modal.removeClass('is-open');
    $('body').removeClass('koopo-ticket--modal-open');
    $modal.find('.koopo-ticket__notice').hide().text('');
  }

  function resetModal($modal) {
    $modal.find('.koopo-ticket__panel').removeClass('is-active');
    $modal.find('.koopo-ticket__panel[data-panel="1"]').addClass('is-active');
    $modal.find('.koopo-ticket__step').removeClass('koopo-ticket__step--active koopo-ticket__step--completed');
    $modal.find('.koopo-ticket__step[data-step="1"]').addClass('koopo-ticket__step--active');
    $modal.find('.koopo-ticket__item input[type="number"]').val(0);
    $modal.find('[data-guest-grid]').empty();
    $modal.find('[data-summary]').empty();
    $modal.find('.koopo-ticket__notice').hide().text('');
  }

  function showNotice($modal, message) {
    $modal.find('.koopo-ticket__notice').text(message).show();
  }

  function getSelections($modal) {
    var selections = [];
    $modal.find('.koopo-ticket__item').each(function () {
      var $item = $(this);
      var qty = parseInt($item.find('input[type="number"]').val(), 10) || 0;
      if (qty > 0) {
        selections.push({
          variation_id: parseInt($item.data('variation-id'), 10),
          ticket_type_id: parseInt($item.data('ticket-type-id'), 10) || 0,
          name: $item.data('name') || '',
          price: parseFloat($item.data('price')) || 0,
          qty: qty,
          max: parseInt($item.data('max'), 10) || 0
        });
      }
    });
    return selections;
  }

  function validateSelections($modal, selections) {
    var globalMax = (window.KOOPO_TICKETS_FRONTEND && KOOPO_TICKETS_FRONTEND.max_tickets_per_order) ? parseInt(KOOPO_TICKETS_FRONTEND.max_tickets_per_order, 10) : 0;
    var totalQty = 0;

    if (!selections.length) {
      return 'Please select at least one ticket.';
    }

    selections.forEach(function (item) {
      totalQty += item.qty;
      if (item.max && item.qty > item.max) {
        throw new Error('Max ' + item.max + ' allowed for ' + item.name + '.');
      }
    });

    if (globalMax && totalQty > globalMax) {
      return 'Maximum ' + globalMax + ' tickets per order.';
    }

    var $scheduleSelect = $modal.find('.koopo-ticket-schedule-select');
    if ($scheduleSelect.length && !$scheduleSelect.val()) {
      return 'Please select an event date.';
    }

    return '';
  }

  function buildGuestInputs($modal, selections) {
    var total = selections.reduce(function (sum, item) { return sum + item.qty; }, 0);
    var $grid = $modal.find('[data-guest-grid]');
    $grid.empty();

    if (total <= 1) return;

    var index = 1;
    selections.forEach(function (item) {
      for (var i = 0; i < item.qty; i += 1) {
        if (index === 1) {
          index += 1;
          continue;
        }
        var card = '<div class="koopo-ticket__guest-card">' +
          '<h6>Guest ' + (index - 1) + ' (' + item.name + ')</h6>' +
          '<div class="koopo-ticket__field"><label>Name</label><input type="text" data-guest-name data-ticket-type="' + item.ticket_type_id + '" data-ticket-name="' + item.name + '"></div>' +
          '<div class="koopo-ticket__field"><label>Email</label><input type="email" data-guest-email data-ticket-type="' + item.ticket_type_id + '" data-ticket-name="' + item.name + '"></div>' +
          '<div class="koopo-ticket__field"><label>Phone</label><input type="tel" data-guest-phone data-ticket-type="' + item.ticket_type_id + '" data-ticket-name="' + item.name + '"></div>' +
        '</div>';
        $grid.append(card);
        index += 1;
      }
    });
  }

  function buildSummary($modal, selections) {
    var $summary = $modal.find('[data-summary]');
    var currency = (window.KOOPO_TICKETS_FRONTEND && KOOPO_TICKETS_FRONTEND.currency_symbol) ? KOOPO_TICKETS_FRONTEND.currency_symbol : '$';
    var total = 0;
    var list = '<div class="koopo-ticket__summary-block">';
    list += '<h4>Tickets</h4>';

    selections.forEach(function (item) {
      var line = item.price * item.qty;
      total += line;
      list += '<div class="koopo-ticket__summary-row"><span>' + item.name + ' x ' + item.qty + '</span><span>' + currency + line.toFixed(2) + '</span></div>';
    });

    list += '<div class="koopo-ticket__summary-row koopo-ticket__summary-total"><span>Total</span><span>' + currency + total.toFixed(2) + '</span></div>';
    list += '</div>';

    var scheduleLabel = $modal.find('input[name="koopo_ticket_schedule_label"]').val();
    if (scheduleLabel) {
      list += '<div class="koopo-ticket__summary-block"><h4>Event Date</h4><div class="koopo-ticket__summary-row"><span>' + scheduleLabel + '</span></div></div>';
    }

    var guests = collectGuests($modal);
    if (guests.length) {
      list += '<div class="koopo-ticket__summary-block"><h4>Guests</h4>';
      guests.forEach(function (guest, index) {
        var parts = [];
        if (guest.name) parts.push(guest.name);
        if (guest.email) parts.push(guest.email);
        if (guest.phone) parts.push(guest.phone);
        var label = parts.length ? parts.join(' • ') : 'Guest ' + (index + 1);
        list += '<div class="koopo-ticket__summary-row"><span>' + label + '</span><span>' + (guest.ticket_name || '') + '</span></div>';
      });
      list += '</div>';
    }

    $summary.html(list);
  }

  function collectGuests($modal) {
    var guests = [];
    $modal.find('[data-guest-name]').each(function () {
      var $input = $(this);
      var name = $input.val();
      var $card = $input.closest('.koopo-ticket__guest-card');
      var email = $card.find('[data-guest-email]').val();
      var phone = $card.find('[data-guest-phone]').val();
      var ticketTypeId = parseInt($input.data('ticket-type'), 10) || 0;
      var ticketName = $input.data('ticket-name') || '';

      if (name || email || phone) {
        guests.push({
          name: name,
          email: email,
          phone: phone,
          ticket_type_id: ticketTypeId,
          ticket_name: ticketName
        });
      }
    });

    return guests;
  }

  function addItemsToCart($modal, selections, done) {
    var ajaxUrl = wc_add_to_cart_params ? wc_add_to_cart_params.wc_ajax_url.replace('%%endpoint%%', 'add_to_cart') : '';
    if (!ajaxUrl) return;

    var attributeKey = $modal.data('attribute-key');
    var productId = parseInt($modal.data('product-id'), 10);
    var eventId = parseInt($modal.data('event-id'), 10);
    var scheduleId = $modal.find('select[name="koopo_ticket_schedule_id"]').val() || $modal.find('input[name="koopo_ticket_schedule_id"]').val() || '';
    var scheduleLabel = $modal.find('input[name="koopo_ticket_schedule_label"]').val() || '';
    var requireSchedule = $modal.find('.koopo-ticket-schedule-select').length ? 1 : 0;

    var contactName = $modal.find('input[name="koopo_ticket_contact_name"]').val() || '';
    var contactEmail = $modal.find('input[name="koopo_ticket_contact_email"]').val() || '';
    var contactPhone = $modal.find('input[name="koopo_ticket_contact_phone"]').val() || '';
    var guests = collectGuests($modal);

    var queue = selections.slice();

    function next() {
      if (!queue.length) {
        done();
        return;
      }

      var item = queue.shift();
      var payload = {
        'add-to-cart': productId,
        product_id: productId,
        variation_id: item.variation_id,
        quantity: item.qty,
        koopo_ticket_event_id: eventId,
        koopo_ticket_type_id: item.ticket_type_id,
        koopo_ticket_schedule_id: scheduleId,
        koopo_ticket_schedule_label: scheduleLabel,
        koopo_ticket_require_schedule: requireSchedule,
        koopo_ticket_contact_name: contactName,
        koopo_ticket_contact_email: contactEmail,
        koopo_ticket_contact_phone: contactPhone,
        koopo_ticket_guests: JSON.stringify(guests || [])
      };

      payload[attributeKey] = item.name;

      $.post(ajaxUrl, payload).always(function () {
        next();
      });
    }

    next();
  }

  $(document).on('click', '.koopo-ticket-open', function (e) {
    e.preventDefault();
    openModal($($(this).data('target')));
  });

  $(document).on('click', '.koopo-ticket__close', function (e) {
    e.preventDefault();
    closeModal($(this).closest('.koopo-ticket__overlay'));
  });

  $(document).on('click', '.koopo-ticket__overlay', function (e) {
    if ($(e.target).hasClass('koopo-ticket__overlay')) {
      closeModal($(this));
    }
  });

  $(document).on('change', '.koopo-ticket-schedule-select', function () {
    var label = $(this).find('option:selected').data('label') || '';
    $(this).closest('.koopo-ticket__modal').find('input[name="koopo_ticket_schedule_label"]').val(label);
  });

  $(document).on('click', '.koopo-ticket-next', function () {
    var $modal = $(this).closest('.koopo-ticket__overlay');
    var $panel = $(this).closest('.koopo-ticket__panel');
    var step = parseInt($panel.data('panel'), 10);

    if (step === 1) {
      var selections = getSelections($modal);
      try {
        var error = validateSelections($modal, selections);
        if (error) {
          showNotice($modal, error);
          return;
        }
      } catch (err) {
        showNotice($modal, err.message);
        return;
      }

      $modal.data('selections', selections);
      buildGuestInputs($modal, selections);
      buildSummary($modal, selections);
    }

    if (step === 2) {
      buildSummary($modal, $modal.data('selections') || []);
    }

    $modal.find('.koopo-ticket__panel').removeClass('is-active');
    $modal.find('.koopo-ticket__panel[data-panel="' + (step + 1) + '"]').addClass('is-active');
    $modal.find('.koopo-ticket__step').removeClass('koopo-ticket__step--active');
    $modal.find('.koopo-ticket__step[data-step="' + (step + 1) + '"]').addClass('koopo-ticket__step--active');
    $modal.find('.koopo-ticket__step[data-step="' + step + '"]').addClass('koopo-ticket__step--completed');
  });

  $(document).on('click', '.koopo-ticket-back', function () {
    var $modal = $(this).closest('.koopo-ticket__overlay');
    var $panel = $(this).closest('.koopo-ticket__panel');
    var step = parseInt($panel.data('panel'), 10);

    $modal.find('.koopo-ticket__panel').removeClass('is-active');
    $modal.find('.koopo-ticket__panel[data-panel="' + (step - 1) + '"]').addClass('is-active');
    $modal.find('.koopo-ticket__step').removeClass('koopo-ticket__step--active');
    $modal.find('.koopo-ticket__step[data-step="' + (step - 1) + '"]').addClass('koopo-ticket__step--active');
  });

  $(document).on('click', '.koopo-ticket-confirm', function () {
    var $modal = $(this).closest('.koopo-ticket__overlay');
    var selections = $modal.data('selections') || [];
    if (!selections.length) {
      showNotice($modal, 'Please select tickets first.');
      return;
    }

    var button = $(this);
    button.prop('disabled', true).addClass('is-loading');

    addItemsToCart($modal, selections, function () {
      var checkoutUrl = (window.KOOPO_TICKETS_FRONTEND && KOOPO_TICKETS_FRONTEND.checkout_url) ? KOOPO_TICKETS_FRONTEND.checkout_url : '';
      if (checkoutUrl) {
        window.location = checkoutUrl;
      }
    });
  });
})(jQuery);
