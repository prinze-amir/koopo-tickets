(function ($) {
  'use strict';

  var api = window.KOOPO_TICKETS_VENDOR || {};
  if (!api.rest || !api.nonce) return;
  var currentDatePrices = {};
  var currentDates = [];
  var currentDateIndex = {};
  var currentCalendar = { year: 0, month: 0, selectedDateKey: '' };

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

  function monthLabel(year, month) {
    var d = new Date(year, month, 1);
    if (d.toLocaleString) {
      return d.toLocaleString(undefined, { month: 'long', year: 'numeric' });
    }
    return (month + 1) + '/' + year;
  }

  function request(path, method, data) {
    var base = api.rest.replace(/\/$/, '');
    var url = base + '/' + path.replace(/^\//, '');
    return $.ajax({
      url: url,
      method: method || 'GET',
      data: data ? JSON.stringify(data) : null,
      contentType: 'application/json',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', api.nonce);
      }
    });
  }

  function showNotice(type, message) {
    var $notice = $('#koopo-ticket-notice');
    if (!$notice.length) return;
    $notice.removeClass('is-error is-success');
    $notice.addClass(type === 'success' ? 'is-success' : 'is-error');
    $notice.text(message).show();
  }

  function clearNotice() {
    var $notice = $('#koopo-ticket-notice');
    if ($notice.length) $notice.hide().text('').removeClass('is-error is-success');
  }

  function fmtCurrency(value) {
    if (!value) return '—';
    if (api.currency_symbol) return api.currency_symbol + parseFloat(value).toFixed(2);
    return parseFloat(value).toFixed(2);
  }

  function fmtSalesWindow(start, end) {
    if (!start && !end) return '—';
    if (start && end) return start + ' → ' + end;
    return start || end;
  }

  function fmtSalesRule(mode, start, end) {
    if (mode === 'event_end') return 'Until event ends';
    if (mode === 'custom') return 'Custom: ' + fmtSalesWindow(start, end);
    return 'Until event starts';
  }

  function statusBadge(status) {
    var normalized = status || 'active';
    var css = normalized === 'inactive' ? 'koopo-tickets-badge is-inactive' : 'koopo-tickets-badge';
    return '<span class="' + css + '">' + normalized + '</span>';
  }

  function applyFilters(items) {
    var search = ($('#koopo-ticket-filter-search').val() || '').toLowerCase();
    var eventId = parseInt($('#koopo-ticket-filter-event').val() || 0, 10);
    var status = $('#koopo-ticket-filter-status').val();
    var visibility = $('#koopo-ticket-filter-visibility').val();

    return items.filter(function (item) {
      if (search && item.title.toLowerCase().indexOf(search) === -1) return false;
      if (eventId && item.event_id !== eventId) return false;
      if (status && item.status !== status) return false;
      if (visibility && item.visibility !== visibility) return false;
      return true;
    });
  }

  function renderRows(items) {
    var $body = $('#koopo-ticket-types-body');
    if (!$body.length) return;
    if (!items.length) {
      $body.html('<tr><td colspan="12">No ticket types yet.</td></tr>');
      return;
    }

    var rows = items.map(function (item) {
      var eventTitle = item.event_title || '—';
      return '<tr>' +
        '<td>' + item.title + '</td>' +
        '<td>' + eventTitle + '</td>' +
        '<td>' + fmtCurrency(item.price) + '</td>' +
        '<td>' + (item.capacity || '—') + '</td>' +
        '<td>' + statusBadge(item.status) + '</td>' +
        '<td>' + (item.visibility || 'public') + '</td>' +
        '<td>' + fmtSalesRule(item.sales_mode, item.sales_start, item.sales_end) + '</td>' +
        '<td>' + (item.sku || '—') + '</td>' +
        '<td>' + (item.product_id ? ('#' + item.product_id) : '—') + '</td>' +
        '<td>' + (item.variation_id ? ('#' + item.variation_id) : '—') + '</td>' +
        '<td>' + (item.max_per_order ? item.max_per_order : '—') + '</td>' +
        '<td>' +
          '<button class="button koopo-edit-ticket" data-id="' + item.id + '">Edit</button> ' +
          '<button class="button koopo-delete-ticket" data-id="' + item.id + '">Delete</button>' +
        '</td>' +
      '</tr>';
    }).join('');

    $body.html(rows);
  }

  function loadTickets() {
    request('ticket-types', 'GET').done(function (items) {
      var filtered = applyFilters(items || []);
      renderRows(filtered);
    });
  }

  function loadEvents() {
    if (!api.events || !api.events.length) return;
    var $select = $('#koopo-ticket-event');
    var $filter = $('#koopo-ticket-filter-event');
    if (!$select.length) return;
    $select.empty();
    $select.append('<option value="">Select event</option>');
    if ($filter.length) {
      $filter.empty();
      $filter.append('<option value="">All events</option>');
    }
    api.events.forEach(function (ev) {
      $select.append('<option value="' + ev.id + '">' + ev.title + '</option>');
      if ($filter.length) {
        $filter.append('<option value="' + ev.id + '">' + ev.title + '</option>');
      }
    });
  }

  function getEventById(eventId) {
    if (!api.events || !api.events.length) return null;
    var id = parseInt(eventId || 0, 10);
    if (!id) return null;
    return api.events.find(function (ev) { return ev.id === id; }) || null;
  }

  function buildDateIndex(dates) {
    var index = {};
    dates.forEach(function (entry) {
      if (!entry || !entry.schedule_id) return;
      var key = entry.date_key || dateKeyFromTs(entry.start_ts);
      if (!key) return;
      entry.date_key = key;
      if (!index[key]) index[key] = [];
      index[key].push(entry);
    });
    return index;
  }

  function findDateByScheduleId(id) {
    var target = parseInt(id, 10);
    if (!target) return null;
    for (var i = 0; i < currentDates.length; i += 1) {
      if (parseInt(currentDates[i].schedule_id, 10) === target) return currentDates[i];
    }
    return null;
  }

  function renderVendorCalendar() {
    var $picker = $('[data-vendor-date-picker]');
    if (!$picker.length) return;
    var year = currentCalendar.year;
    var month = currentCalendar.month;
    var selectedDateKey = currentCalendar.selectedDateKey || '';

    var minDate = null;
    var maxDate = null;
    currentDates.forEach(function (entry) {
      if (!entry.date_key) return;
      var parts = entry.date_key.split('-');
      if (parts.length !== 3) return;
      var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      if (!minDate || d < minDate) minDate = d;
      if (!maxDate || d > maxDate) maxDate = d;
    });

    var header = '<div class="koopo-ticket-datepicker__header">' +
      '<button type="button" class="koopo-ticket-datepicker__nav" data-vendor-nav="prev" ' +
        ((minDate && new Date(year, month, 1) <= new Date(minDate.getFullYear(), minDate.getMonth(), 1)) ? 'disabled' : '') +
      '>&lsaquo;</button>' +
      '<span class="koopo-ticket-datepicker__month">' + escapeHtml(monthLabel(year, month)) + '</span>' +
      '<button type="button" class="koopo-ticket-datepicker__nav" data-vendor-nav="next" ' +
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
      var isAvailable = !!currentDateIndex[dateKey];
      var classes = ['koopo-ticket-datepicker__cell'];
      if (isAvailable) classes.push('is-available');
      if (!isAvailable) classes.push('is-unavailable');
      if (selectedDateKey === dateKey) classes.push('is-selected');
      cells.push(
        '<button type="button" class="' + classes.join(' ') + '" data-vendor-day data-date-key="' + dateKey + '" ' +
          (isAvailable ? '' : 'disabled') + '>' + day + '</button>'
      );
    }

    var grid = '<div class="koopo-ticket-datepicker__grid">' + cells.join('') + '</div>';
    $picker.html(header + weekdayRow + grid);
  }

  function renderVendorTimes(dateKey) {
    var $times = $('[data-vendor-date-times]');
    var list = currentDateIndex[dateKey] || [];
    if (!list.length) {
      $times.html('<span class="koopo-ticket-time-empty">No times available.</span>');
      return;
    }

    var selectedId = parseInt($('#koopo-ticket-date-select').val(), 10) || 0;
    var buttons = list.map(function (entry) {
      var label = entry.time || entry.label || 'Time';
      var classes = ['koopo-ticket-time-option'];
      if (parseInt(entry.schedule_id, 10) === selectedId) classes.push('is-selected');
      return '<button type="button" class="' + classes.join(' ') + '" data-vendor-time data-schedule-id="' +
        entry.schedule_id + '" data-schedule-label="' + escapeHtml(entry.label || label) + '">' + escapeHtml(label) + '</button>';
    });
    $times.html(buttons.join(''));
  }

  function selectVendorSchedule(entry) {
    if (!entry) return;
    if (entry.date_key) {
      currentCalendar.selectedDateKey = entry.date_key;
      renderVendorCalendar();
    }
    $('#koopo-ticket-date-select').val(entry.schedule_id).trigger('change');
  }

  function renderDatePrices(eventId, existing) {
    var $wrap = $('#koopo-ticket-date-prices');
    var $list = $wrap.find('.koopo-ticket-date-prices__list');
    var $price = $('#koopo-ticket-date-price');
    var ev = getEventById(eventId);
    var dates = (ev && Array.isArray(ev.dates)) ? ev.dates : [];
    currentDates = dates;

    if (!dates.length || dates.length < 2) {
      $wrap.hide();
      $list.empty();
      $price.val('');
      $('[data-vendor-date-picker]').empty();
      $('[data-vendor-date-times]').empty();
      $('#koopo-ticket-date-select').val('');
      currentDateIndex = {};
      currentCalendar = { year: 0, month: 0, selectedDateKey: '' };
      return;
    }

    currentDatePrices = existing || {};
    currentDateIndex = buildDateIndex(dates);

    var defaultEntry = null;
    var existingKeys = Object.keys(currentDatePrices || {});
    if (existingKeys.length) {
      defaultEntry = findDateByScheduleId(existingKeys[0]);
    }
    if (!defaultEntry) {
      defaultEntry = currentDates[0];
    }

    var baseDateKey = defaultEntry ? (defaultEntry.date_key || dateKeyFromTs(defaultEntry.start_ts)) : '';
    if (baseDateKey) {
      var parts = baseDateKey.split('-');
      currentCalendar.year = parseInt(parts[0], 10);
      currentCalendar.month = parseInt(parts[1], 10) - 1;
      currentCalendar.selectedDateKey = baseDateKey;
    }

    renderVendorCalendar();
    renderVendorTimes(currentCalendar.selectedDateKey);
    if (defaultEntry) {
      selectVendorSchedule(defaultEntry);
    }

    renderDatePriceList();
    syncDatePriceInput();
    $wrap.show();
  }

  function renderDatePriceList() {
    var $list = $('#koopo-ticket-date-prices').find('.koopo-ticket-date-prices__list');
    if (!$list.length) return;
    var rows = [];
    var labelIndex = {};
    currentDates.forEach(function (date) {
      labelIndex[date.schedule_id] = date.label || 'Date';
    });

    Object.keys(currentDatePrices).forEach(function (key) {
      var price = currentDatePrices[key];
      if (price === '' || price === null || price === undefined) return;
      rows.push(
        '<div class="koopo-ticket-date-price" data-schedule-id="' + key + '">' +
          '<span class="koopo-ticket-date-label">' + (labelIndex[key] || 'Date') + '</span>' +
          '<span>$' + price + '.00</span>' +
          '<button type="button" class="button koopo-ticket-date-remove">Remove</button>' +
        '</div>'
      );
    });

    $list.html(rows.join('') || '<div class="koopo-tickets-note">No date-specific prices set.</div>');
  }

  function syncDatePriceInput() {
    var $select = $('#koopo-ticket-date-select');
    var $price = $('#koopo-ticket-date-price');
    var id = $select.val();
    if (!id) {
      $price.val('');
      return;
    }
    var existing = currentDatePrices[id];
    $price.val(existing !== undefined ? existing : '');
    $('[data-vendor-date-times] .koopo-ticket-time-option').removeClass('is-selected')
      .filter('[data-schedule-id="' + id + '"]').addClass('is-selected');
  }

  function collectDatePrices() {
    var $wrap = $('#koopo-ticket-date-prices');
    if (!$wrap.is(':visible')) return null;
    var out = {};
    Object.keys(currentDatePrices).forEach(function (key) {
      var val = currentDatePrices[key];
      if (val === '' || val === null || val === undefined) return;
      out[key] = parseFloat(val);
    });
    return Object.keys(out).length ? out : null;
  }

  function bindCreate() {
    $('#koopo-ticket-create').on('submit', function (e) {
      e.preventDefault();
      clearNotice();

      var payload = {
        title: $('#koopo-ticket-title').val(),
        event_id: parseInt($('#koopo-ticket-event').val(), 10) || null,
        price: parseFloat($('#koopo-ticket-price').val() || 0),
        capacity: parseInt($('#koopo-ticket-capacity').val(), 10) || 0,
        unlimited_capacity: $('#koopo-ticket-unlimited').is(':checked') ? 1 : 0,
        status: $('#koopo-ticket-status').val(),
        visibility: $('#koopo-ticket-visibility').val(),
        sales_mode: $('#koopo-ticket-sales-mode').val(),
        sales_start: $('#koopo-ticket-sales-start').val(),
        sales_end: $('#koopo-ticket-sales-end').val(),
        sku: $('#koopo-ticket-sku').val(),
        max_per_order: parseInt($('#koopo-ticket-max').val(), 10) || 0
      };
      var datePrices = collectDatePrices();
      if (datePrices !== null) {
        payload.date_prices = datePrices;
      }

      if (!payload.title) {
        showNotice('error', 'Ticket name is required.');
        return;
      }
      if (!payload.event_id) {
        showNotice('error', 'Event is required.');
        return;
      }
      if (payload.sales_mode === 'custom' && (!payload.sales_start || !payload.sales_end)) {
        showNotice('error', 'Sales start and end are required for custom mode.');
        return;
      }
      if (payload.price < 0 || payload.capacity < 0) {
        showNotice('error', 'Price and capacity must be zero or more.');
        return;
      }

      var id = $('#koopo-ticket-id').val();
      var endpoint = id ? ('ticket-types/' + id) : 'ticket-types';
      var $submit = $('#koopo-ticket-submit');
      $submit.prop('disabled', true).addClass('is-loading');

      request(endpoint, 'POST', payload).done(function () {
        $('#koopo-ticket-create')[0].reset();
        $('#koopo-ticket-id').val('');
        $('#koopo-ticket-submit').text('Create Ticket Type');
        $('#koopo-ticket-cancel').hide();
        currentDatePrices = {};
        renderDatePrices(null, {});
        toggleCustomDates();
        showNotice('success', id ? 'Ticket type updated.' : 'Ticket type created.');
        loadTickets();
      }).fail(function (xhr) {
        var msg = 'Could not save ticket type.';
        if (xhr && xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        showNotice('error', msg);
      }).always(function () {
        $submit.prop('disabled', false).removeClass('is-loading');
      });
    });
  }

  function bindDelete() {
    $('#koopo-ticket-types-body').on('click', '.koopo-delete-ticket', function () {
      var id = $(this).data('id');
      if (!id) return;
      if (!window.confirm('Delete this ticket type?')) return;
      var $btn = $(this);
      $btn.prop('disabled', true).addClass('is-loading');
      request('ticket-types/' + id, 'DELETE').done(function () {
        loadTickets();
      }).always(function () {
        $btn.prop('disabled', false).removeClass('is-loading');
      });
    });
  }

  function bindEdit() {
    $('#koopo-ticket-types-body').on('click', '.koopo-edit-ticket', function () {
      clearNotice();
      var id = $(this).data('id');
      if (!id) return;

      request('ticket-types/' + id, 'GET').done(function (item) {
        if (!item || !item.id) return;
        $('#koopo-ticket-id').val(item.id);
        $('#koopo-ticket-title').val(item.title);
        $('#koopo-ticket-event').val(item.event_id || '');
        $('#koopo-ticket-price').val(item.price || '');
        $('#koopo-ticket-capacity').val(item.capacity || '');
        $('#koopo-ticket-unlimited').prop('checked', !!item.unlimited_capacity);
        if (item.unlimited_capacity) {
          $('#koopo-ticket-capacity').val('0').prop('disabled', true);
        } else {
          $('#koopo-ticket-capacity').prop('disabled', false);
          if (!$('#koopo-ticket-capacity').val()) {
            $('#koopo-ticket-capacity').val('100');
          }
        }
        $('#koopo-ticket-status').val(item.status || 'active');
        $('#koopo-ticket-visibility').val(item.visibility || 'public');
        $('#koopo-ticket-sales-start').val(item.sales_start || '');
        $('#koopo-ticket-sales-end').val(item.sales_end || '');
        $('#koopo-ticket-sales-mode').val(item.sales_mode || 'event_start');
        $('#koopo-ticket-sku').val(item.sku || '');
        $('#koopo-ticket-max').val(item.max_per_order || '');
        currentDatePrices = item.date_prices || {};
        renderDatePrices(item.event_id, currentDatePrices);
        $('#koopo-ticket-submit').text('Update Ticket Type');
        $('#koopo-ticket-cancel').show();
        toggleCustomDates();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });

    $('#koopo-ticket-cancel').on('click', function () {
      clearNotice();
      $('#koopo-ticket-create')[0].reset();
      $('#koopo-ticket-id').val('');
      $('#koopo-ticket-submit').text('Create Ticket Type');
      $('#koopo-ticket-cancel').hide();
      $('#koopo-ticket-unlimited').prop('checked', false);
      $('#koopo-ticket-capacity').prop('disabled', false).val('100');
      currentDatePrices = {};
      renderDatePrices(null, {});
    });
  }

  function toggleCustomDates() {
    var mode = $('#koopo-ticket-sales-mode').val();
    $('.koopo-ticket-custom-dates').toggle(mode === 'custom');
  }

  function bindFilters() {
    $('#koopo-ticket-filter-search').on('input', function () {
      loadTickets();
    });
    $('#koopo-ticket-filter-event, #koopo-ticket-filter-status, #koopo-ticket-filter-visibility').on('change', function () {
      loadTickets();
    });
  }

  $(function () {
    loadEvents();
    loadTickets();
    bindCreate();
    bindDelete();
    bindEdit();
    bindFilters();
    $('#koopo-ticket-sales-mode').on('change', toggleCustomDates);
    $('#koopo-ticket-unlimited').on('change', function () {
      var checked = $(this).is(':checked');
      if (checked) {
        $('#koopo-ticket-capacity').val('0').prop('disabled', true);
      } else {
        $('#koopo-ticket-capacity').prop('disabled', false);
        if (!$('#koopo-ticket-capacity').val()) {
          $('#koopo-ticket-capacity').val('100');
        }
      }
    });
    $('#koopo-ticket-event').on('change', function () {
      currentDatePrices = {};
      renderDatePrices(parseInt($(this).val(), 10) || 0, currentDatePrices);
    });
    $('#koopo-ticket-date-select').on('change', function () {
      syncDatePriceInput();
    });
    $('#koopo-ticket-date-apply').on('click', function () {
      var id = $('#koopo-ticket-date-select').val();
      if (!id) return;
      var raw = $('#koopo-ticket-date-price').val();
      if (raw === '') {
        delete currentDatePrices[id];
      } else {
        currentDatePrices[id] = parseFloat(raw);
      }
      renderDatePriceList();
      syncDatePriceInput();
    });
    $('#koopo-ticket-date-prices').on('click', '.koopo-ticket-date-remove', function () {
      var id = $(this).closest('.koopo-ticket-date-price').data('schedule-id');
      if (!id) return;
      delete currentDatePrices[id];
      renderDatePriceList();
      syncDatePriceInput();
    });
    $(document).on('click', '[data-vendor-nav]', function () {
      var dir = $(this).data('vendor-nav');
      var year = currentCalendar.year;
      var month = currentCalendar.month;
      if (!year && year !== 0) return;
      month = dir === 'prev' ? month - 1 : month + 1;
      if (month < 0) { month = 11; year -= 1; }
      if (month > 11) { month = 0; year += 1; }
      currentCalendar.year = year;
      currentCalendar.month = month;
      renderVendorCalendar();
      var prefix = year + '-' + padNumber(month + 1) + '-';
      var keys = Object.keys(currentDateIndex).filter(function (key) { return key.indexOf(prefix) === 0; }).sort();
      if (keys.length) {
        currentCalendar.selectedDateKey = keys[0];
        renderVendorTimes(keys[0]);
        var options = currentDateIndex[keys[0]] || [];
        if (options.length) {
          selectVendorSchedule(options[0]);
        }
      }
    });
    $(document).on('click', '[data-vendor-day]', function () {
      var dateKey = $(this).data('date-key');
      if (!dateKey) return;
      currentCalendar.selectedDateKey = dateKey;
      renderVendorCalendar();
      renderVendorTimes(dateKey);
      var options = currentDateIndex[dateKey] || [];
      if (options.length) {
        selectVendorSchedule(options[0]);
      }
    });
    $(document).on('click', '[data-vendor-time]', function () {
      var scheduleId = $(this).data('schedule-id');
      var entry = findDateByScheduleId(scheduleId);
      if (entry) {
        selectVendorSchedule(entry);
      }
    });
    toggleCustomDates();
  });
})(jQuery);
