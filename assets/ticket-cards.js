(function ($) {
  'use strict';

  function openModal($modal) {
    $modal.addClass('is-open');
    $('body').addClass('koopo-ticket--modal-open');
    resetModal($modal);
    initSchedulePicker($modal);
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
    $modal.find('[data-guest-grid]').hide().empty();
    $modal.find('[data-guest-toggle]').hide().text('Add guest info (optional)');
    $modal.find('[data-summary]').empty();
    $modal.find('.koopo-ticket__notice').hide().text('');
  }

  function showNotice($modal, message) {
    $modal.find('.koopo-ticket__notice').text(message).show();
  }

  function parsePriceMap(value) {
    if (!value) return {};
    var data = value;
    if (typeof value === 'string') {
      try {
        data = JSON.parse(value);
      } catch (e) {
        return {};
      }
    }

    if (Array.isArray(data)) {
      var map = {};
      data.forEach(function (entry, idx) {
        if (entry && typeof entry === 'object' && !Array.isArray(entry)) {
          var id = entry.schedule_id || entry.id || entry.key || entry.date_id;
          var price = (entry.price !== undefined) ? entry.price : (entry.value !== undefined ? entry.value : null);
          if (id !== undefined && price !== null && price !== '') {
            map[id.toString()] = parseFloat(price);
            return;
          }
        }
        if (entry !== null && entry !== undefined && entry !== '') {
          map[idx.toString()] = parseFloat(entry);
        }
      });
      return map;
    }

    if (data && typeof data === 'object') return data;
    return {};
  }

  function formatPrice(value, allowFreeLabel) {
    var currency = (window.KOOPO_TICKETS_FRONTEND && KOOPO_TICKETS_FRONTEND.currency_symbol) ? KOOPO_TICKETS_FRONTEND.currency_symbol : '$';
    var num = parseFloat(value);
    if (isNaN(num)) return '—';
    if (!num && allowFreeLabel !== false) return 'Free';
    return currency + num.toFixed(2);
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      })[char];
    });
  }

  function padNumber(value) {
    return value < 10 ? '0' + value : '' + value;
  }

  function dateKeyFromTs(ts) {
    var stamp = parseInt(ts, 10);
    if (!stamp) return '';
    var d = new Date(stamp * 1000);
    return d.getFullYear() + '-' + padNumber(d.getMonth() + 1) + '-' + padNumber(d.getDate());
  }

  function parseSchedules(raw) {
    if (!raw) return [];
    var data = raw;
    if (typeof raw === 'string') {
      try {
        data = JSON.parse(raw);
      } catch (e) {
        return [];
      }
    }
    if (!Array.isArray(data)) return [];

    return data.map(function (entry) {
      if (!entry || typeof entry !== 'object') return null;
      var scheduleId = parseInt(entry.schedule_id || entry.id || 0, 10);
      if (!scheduleId) return null;
      var startTs = parseInt(entry.start_ts || entry.startTimestamp || 0, 10) || 0;
      var dateKey = entry.date_key || dateKeyFromTs(startTs);
      return {
        schedule_id: scheduleId,
        label: entry.label || '',
        date: entry.date || '',
        time: entry.time || '',
        start_ts: startTs,
        date_key: dateKey
      };
    }).filter(Boolean);
  }

  function buildScheduleIndex(schedules) {
    var index = {};
    schedules.forEach(function (entry) {
      var key = entry.date_key || dateKeyFromTs(entry.start_ts);
      if (!key) return;
      entry.date_key = key;
      if (!index[key]) index[key] = [];
      index[key].push(entry);
    });
    return index;
  }

  function findScheduleById(schedules, scheduleId) {
    var target = parseInt(scheduleId, 10);
    if (!target) return null;
    for (var i = 0; i < schedules.length; i += 1) {
      if (parseInt(schedules[i].schedule_id, 10) === target) return schedules[i];
    }
    return null;
  }

  function monthLabel(year, month) {
    var d = new Date(year, month, 1);
    if (d.toLocaleString) {
      return d.toLocaleString(undefined, { month: 'long', year: 'numeric' });
    }
    return (month + 1) + '/' + year;
  }

  function initSchedulePicker($modal) {
    var $picker = $modal.find('[data-schedule-picker]');
    if (!$picker.length) return;

    var rawSchedules = $picker.attr('data-schedules') || $picker.data('schedules');
    var schedules = parseSchedules(rawSchedules);
    if (!schedules.length) return;

    var index = buildScheduleIndex(schedules);
    $picker.data('schedule-index', index);
    $picker.data('schedule-items', schedules);

    var $scheduleId = $picker.find('input[name="koopo_ticket_schedule_id"]');
    var currentId = $scheduleId.val();
    var selected = findScheduleById(schedules, currentId) || schedules[0];
    if (!selected) return;

    var selectedDateKey = selected.date_key || dateKeyFromTs(selected.start_ts);
    var selectedDate = new Date(selected.start_ts * 1000);
    if (selectedDateKey) {
      var parts = selectedDateKey.split('-');
      if (parts.length === 3) {
        selectedDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      }
    }

    $picker.data('calendar-year', selectedDate.getFullYear());
    $picker.data('calendar-month', selectedDate.getMonth());
    $picker.data('selected-date-key', selectedDateKey);

    renderScheduleCalendar($picker);
    renderScheduleTimes($picker);
    selectScheduleEntry($picker, selected);
  }

  function renderScheduleCalendar($picker) {
    var schedules = $picker.data('schedule-items') || [];
    var index = $picker.data('schedule-index') || {};
    if (!schedules.length) return;

    var year = $picker.data('calendar-year');
    var month = $picker.data('calendar-month');
    var selectedDateKey = $picker.data('selected-date-key') || '';

    var minDate = null;
    var maxDate = null;
    schedules.forEach(function (entry) {
      if (!entry.date_key) return;
      var parts = entry.date_key.split('-');
      if (parts.length !== 3) return;
      var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      if (!minDate || d < minDate) minDate = d;
      if (!maxDate || d > maxDate) maxDate = d;
    });

    if (typeof year !== 'number' || typeof month !== 'number') {
      var base = minDate || new Date();
      year = base.getFullYear();
      month = base.getMonth();
      $picker.data('calendar-year', year);
      $picker.data('calendar-month', month);
    }

    var header = '<div class="koopo-ticket-datepicker__header">' +
      '<button type="button" class="koopo-ticket-datepicker__nav" data-schedule-nav="prev" ' +
        ((minDate && new Date(year, month, 1) <= new Date(minDate.getFullYear(), minDate.getMonth(), 1)) ? 'disabled' : '') +
      '>&lsaquo;</button>' +
      '<span class="koopo-ticket-datepicker__month">' + escapeHtml(monthLabel(year, month)) + '</span>' +
      '<button type="button" class="koopo-ticket-datepicker__nav" data-schedule-nav="next" ' +
        ((maxDate && new Date(year, month, 1) >= new Date(maxDate.getFullYear(), maxDate.getMonth(), 1)) ? 'disabled' : '') +
      '>&rsaquo;</button>' +
    '</div>';

    var weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var weekdayRow = '<div class="koopo-ticket-datepicker__weekdays">' +
      weekdays.map(function (day) { return '<span>' + day + '</span>'; }).join('') +
    '</div>';

    var firstDay = new Date(year, month, 1);
    var startWeekday = firstDay.getDay();
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var cells = [];

    for (var i = 0; i < startWeekday; i += 1) {
      cells.push('<span class="koopo-ticket-datepicker__cell is-empty"></span>');
    }

    for (var day = 1; day <= daysInMonth; day += 1) {
      var dateKey = year + '-' + padNumber(month + 1) + '-' + padNumber(day);
      var isAvailable = !!index[dateKey];
      var classes = ['koopo-ticket-datepicker__cell'];
      if (isAvailable) classes.push('is-available');
      if (!isAvailable) classes.push('is-unavailable');
      if (selectedDateKey === dateKey) classes.push('is-selected');
      cells.push(
        '<button type="button" class="' + classes.join(' ') + '" data-schedule-day data-date-key="' + dateKey + '" ' +
          (isAvailable ? '' : 'disabled') + '>' + day + '</button>'
      );
    }

    var grid = '<div class="koopo-ticket-datepicker__grid">' + cells.join('') + '</div>';
    $picker.find('[data-schedule-calendar]').html(header + weekdayRow + grid);
  }

  function renderScheduleTimes($picker) {
    var index = $picker.data('schedule-index') || {};
    var selectedDateKey = $picker.data('selected-date-key') || '';
    var options = index[selectedDateKey] || [];
    var $timeWrap = $picker.find('[data-schedule-times]');

    if (!options.length) {
      $timeWrap.html('<span class="koopo-ticket-time-empty">No times available.</span>');
      return;
    }

    var selectedId = parseInt($picker.find('input[name="koopo_ticket_schedule_id"]').val(), 10) || 0;
    var buttons = options.map(function (entry) {
      var label = entry.time || entry.label || 'Time';
      var classes = ['koopo-ticket-time-option'];
      if (parseInt(entry.schedule_id, 10) === selectedId) classes.push('is-selected');
      return '<button type="button" class="' + classes.join(' ') + '" data-schedule-time data-schedule-id="' +
        entry.schedule_id + '" data-schedule-label="' + escapeHtml(entry.label || label) + '">' + escapeHtml(label) + '</button>';
    });
    $timeWrap.html(buttons.join(''));
  }

  function selectScheduleEntry($picker, entry) {
    if (!entry) return;
    var $modal = $picker.closest('.koopo-ticket__overlay');
    $picker.find('input[name="koopo_ticket_schedule_id"]').val(entry.schedule_id);
    $picker.find('input[name="koopo_ticket_schedule_label"]').val(entry.label || '');
    $picker.data('selected-date-key', entry.date_key || dateKeyFromTs(entry.start_ts));
    $picker.find('[data-schedule-time]').removeClass('is-selected')
      .filter('[data-schedule-id="' + entry.schedule_id + '"]').addClass('is-selected');
    updatePrices($modal);
  }

  function updatePrices($modal) {
    var scheduleId = $modal.find('select[name="koopo_ticket_schedule_id"]').val() || $modal.find('input[name="koopo_ticket_schedule_id"]').val() || '';
    scheduleId = scheduleId ? scheduleId.toString() : '';
    var scheduleLabel = $modal.find('input[name="koopo_ticket_schedule_label"]').val() || '';

    $modal.find('.koopo-ticket__item').each(function () {
      var $item = $(this);
      var base = parseFloat($item.data('price-base')) || 0;
      var mapSource = $item.attr('data-price-map');
      if (!mapSource) {
        mapSource = $item.data('price-map');
      }
      var map = parsePriceMap(mapSource);
      var price = base;
      if (scheduleId && map && typeof map === 'object' && map[scheduleId] !== undefined && map[scheduleId] !== '') {
        price = parseFloat(map[scheduleId]) || 0;
      } else if (scheduleLabel && map && typeof map === 'object' && map[scheduleLabel] !== undefined && map[scheduleLabel] !== '') {
        price = parseFloat(map[scheduleLabel]) || 0;
      }
      $item.data('price', price);
      $item.attr('data-price', price);
      $item.find('[data-price-label]').text(formatPrice(price, true));
    });
  }

  function getSelections($modal) {
    var selections = [];
    $modal.find('.koopo-ticket__item').each(function () {
      var $item = $(this);
      var qty = parseInt($item.find('input[type="number"]').val(), 10) || 0;
      if (qty > 0) {
        var attrPrice = parseFloat($item.attr('data-price'));
        var itemPrice = !isNaN(attrPrice) ? attrPrice : (parseFloat($item.data('price')) || 0);
        selections.push({
          variation_id: parseInt($item.data('variation-id'), 10),
          ticket_type_id: parseInt($item.data('ticket-type-id'), 10) || 0,
          name: $item.data('name') || '',
          price: itemPrice,
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
    var $schedulePicker = $modal.find('[data-schedule-picker]');
    if ($schedulePicker.length) {
      var selectedId = $schedulePicker.find('input[name="koopo_ticket_schedule_id"]').val();
      if (!selectedId) {
        return 'Please select an event date.';
      }
    }

    return '';
  }

  function buildGuestInputs($modal, selections) {
    var total = selections.reduce(function (sum, item) { return sum + item.qty; }, 0);
    var $grid = $modal.find('[data-guest-grid]');
    var $toggle = $modal.find('[data-guest-toggle]');
    $grid.empty();

    if (total <= 1) {
      $grid.hide();
      $toggle.hide();
      return;
    }

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
    $grid.hide();
    $toggle.show().text('Add guest info (optional)');
  }

  function buildSummary($modal, selections) {
    var $summary = $modal.find('[data-summary]');
    var total = 0;
    var list = '<div class="koopo-ticket__summary-block">';
    list += '<h4>Tickets</h4>';

    selections.forEach(function (item) {
      var line = item.price * item.qty;
      total += line;
      list += '<div class="koopo-ticket__summary-row"><span>' + item.name + ' x ' + item.qty + '</span><span>' + formatPrice(line, false) + '</span></div>';
    });

    list += '<div class="koopo-ticket__summary-row koopo-ticket__summary-total"><span>Total</span><span>' + formatPrice(total, false) + '</span></div>';
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
    var requireSchedule = $modal.find('.koopo-ticket-schedule-select, [data-schedule-picker]').length ? 1 : 0;

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
    var $modal = $($(this).data('target'));
    openModal($modal);
    updatePrices($modal);
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

  $(document).on('click', '[data-guest-toggle]', function () {
    var $btn = $(this);
    var $modal = $btn.closest('.koopo-ticket__overlay');
    var $grid = $modal.find('[data-guest-grid]');
    if (!$grid.children().length) return;
    var show = !$grid.is(':visible');
    $grid.toggle(show);
    $btn.text(show ? 'Hide guest info' : 'Add guest info (optional)');
  });

  $(document).on('change', '.koopo-ticket-schedule-select', function () {
    var label = $(this).find('option:selected').data('label') || '';
    var $modal = $(this).closest('.koopo-ticket__modal');
    $modal.find('input[name="koopo_ticket_schedule_label"]').val(label);
    updatePrices($modal);
  });

  $(document).on('click', '[data-schedule-nav]', function () {
    var $picker = $(this).closest('[data-schedule-picker]');
    var dir = $(this).data('schedule-nav');
    var year = $picker.data('calendar-year');
    var month = $picker.data('calendar-month');
    if (typeof year !== 'number' || typeof month !== 'number') return;
    month = dir === 'prev' ? month - 1 : month + 1;
    if (month < 0) { month = 11; year -= 1; }
    if (month > 11) { month = 0; year += 1; }
    $picker.data('calendar-year', year);
    $picker.data('calendar-month', month);
    renderScheduleCalendar($picker);
    var index = $picker.data('schedule-index') || {};
    var prefix = year + '-' + padNumber(month + 1) + '-';
    var keys = Object.keys(index).filter(function (key) { return key.indexOf(prefix) === 0; }).sort();
    if (keys.length) {
      $picker.data('selected-date-key', keys[0]);
      renderScheduleTimes($picker);
      var options = index[keys[0]] || [];
      if (options.length) {
        selectScheduleEntry($picker, options[0]);
      }
    }
  });

  $(document).on('click', '[data-schedule-day]', function () {
    var $day = $(this);
    var $picker = $day.closest('[data-schedule-picker]');
    var dateKey = $day.data('date-key');
    if (!dateKey) return;
    $picker.data('selected-date-key', dateKey);
    renderScheduleCalendar($picker);
    renderScheduleTimes($picker);
    var index = $picker.data('schedule-index') || {};
    var options = index[dateKey] || [];
    if (options.length) {
      selectScheduleEntry($picker, options[0]);
    }
  });

  $(document).on('click', '[data-schedule-time]', function () {
    var $time = $(this);
    var $picker = $time.closest('[data-schedule-picker]');
    var scheduleId = $time.data('schedule-id');
    var schedules = $picker.data('schedule-items') || [];
    var entry = findScheduleById(schedules, scheduleId);
    if (entry) {
      selectScheduleEntry($picker, entry);
    }
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
