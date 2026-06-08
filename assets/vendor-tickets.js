(function ($) {
  'use strict';

  var api = window.KOOPO_TICKETS_VENDOR || {};
  if (!api.rest || !api.nonce) return;

  var selectedEventId = 0;
  var selectedEvent = null;
  var currentDatePrices = {};
  var currentDates = [];
  var currentDateIndex = {};
  var currentCalendar = { year: 0, month: 0, selectedDateKey: '' };
  var currentStep = 1;
  var totalSteps = 2;
  var eventSearchTimer = null;
  var analyticsPalette = ['#1f5fbf', '#ff8c00', '#2d9d78', '#6d4cce', '#d93f6f', '#f4b400', '#0097a7', '#7b8a8b', '#e65100', '#3949ab'];
  var eventsState = {
    items: Array.isArray(api.events) ? api.events : [],
    currentPage: 1,
    totalPages: 1,
    perPage: parseInt(api.events_per_page || 12, 10) || 12
  };
  var ticketsState = {
    items: [],
    currentPage: 1,
    totalPages: 1,
    perPage: parseInt(api.tickets_per_page || 10, 10) || 10
  };

  function isMobileViewport() {
    return window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
  }

  function syncMobileEventMode() {
    var $dashboard = $('#koopo-ticket-dashboard');
    if (!$dashboard.length) return;
    var active = !!selectedEventId && isMobileViewport();
    $dashboard.toggleClass('koopo-mobile-event-selected', active);
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
    if ($notice.length) {
      $notice.hide().text('').removeClass('is-error is-success');
    }
  }

  function fmtCurrency(value) {
    if (!value) return '—';
    if (api.currency_symbol) return api.currency_symbol + parseFloat(value).toFixed(2);
    return parseFloat(value).toFixed(2);
  }

  function fmtMoney(value) {
    var amount = parseFloat(value || 0);
    if (isNaN(amount)) amount = 0;
    if (api.currency_symbol) return api.currency_symbol + amount.toFixed(2);
    return amount.toFixed(2);
  }

  function renderPagination(containerSelector, current, total, type) {
    var $container = $(containerSelector);
    if (!$container.length) return;

    if (total <= 1) {
      $container.hide().empty();
      return;
    }

    var pages = [];
    var start = Math.max(1, current - 2);
    var end = Math.min(total, current + 2);
    for (var page = start; page <= end; page += 1) {
      pages.push(
        '<button type="button" class="button koopo-tickets-page' + (page === current ? ' is-current' : '') + '" data-page-type="' + type + '" data-page="' + page + '">' +
          page +
        '</button>'
      );
    }

    $container.html(
      '<button type="button" class="button koopo-tickets-page-nav" data-page-type="' + type + '" data-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '>Previous</button>' +
      '<div class="koopo-tickets-pagination__pages">' + pages.join('') + '</div>' +
      '<span class="koopo-tickets-pagination__label">Page ' + current + ' of ' + total + '</span>' +
      '<button type="button" class="button koopo-tickets-page-nav" data-page-type="' + type + '" data-page="' + (current + 1) + '"' + (current >= total ? ' disabled' : '') + '>Next</button>'
    ).show();
  }

  function renderAnalytics(data) {
    var stats = data || {};
    $('#koopo-analytics-tickets').text(parseInt(stats.tickets_sold || 0, 10));
    $('#koopo-analytics-gross').text(fmtMoney(stats.gross_sales || 0));
    $('#koopo-analytics-net').text(fmtMoney(stats.net_sales || 0));
    renderAnalyticsPie(stats.event_breakdown || []);
  }

  function renderAnalyticsPie(breakdown) {
    var $pie = $('#koopo-analytics-pie');
    var $legend = $('#koopo-analytics-legend');
    var $total = $('#koopo-analytics-pie-total');
    if (!$pie.length || !$legend.length || !$total.length) return;

    var list = Array.isArray(breakdown) ? breakdown : [];
    list = list.filter(function (item) {
      return parseFloat(item.gross_sales || 0) > 0;
    });

    var total = list.reduce(function (sum, item) {
      return sum + parseFloat(item.gross_sales || 0);
    }, 0);

    $total.text(fmtMoney(total));

    if (!list.length || total <= 0) {
      $pie.css('background', 'conic-gradient(#e5e5e5 0 100%)');
      $legend.html('<div class="koopo-tickets-note">No event sales yet.</div>');
      return;
    }

    var segments = [];
    var legendHtml = [];
    var start = 0;

    list.forEach(function (item, index) {
      var value = parseFloat(item.gross_sales || 0);
      if (value <= 0) return;
      var ratio = value / total;
      var end = start + (ratio * 100);
      var color = analyticsPalette[index % analyticsPalette.length];
      var title = item.event_title || ('Event #' + (item.event_id || ''));

      segments.push(color + ' ' + start.toFixed(2) + '% ' + end.toFixed(2) + '%');
      legendHtml.push(
        '<div class="koopo-ticket-analytics-legend__item">' +
          '<div class="koopo-ticket-analytics-legend__left">' +
            '<span class="koopo-ticket-analytics-legend__dot" style="background:' + color + ';"></span>' +
            '<span class="koopo-ticket-analytics-legend__label">' + escapeHtml(title) + '</span>' +
          '</div>' +
          '<span class="koopo-ticket-analytics-legend__value">' + fmtMoney(value) + ' (' + (ratio * 100).toFixed(1) + '%)</span>' +
        '</div>'
      );
      start = end;
    });

    $pie.css('background', 'conic-gradient(' + segments.join(', ') + ')');
    $legend.html(legendHtml.join(''));
  }

  function loadAnalytics() {
    if (api.disable_analytics) {
      renderAnalytics(null);
      return;
    }

    request('vendor/ticket-analytics', 'GET')
      .done(function (stats) {
        renderAnalytics(stats || {});
      })
      .fail(function () {
        renderAnalytics(null);
      });
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

  function getEventById(eventId) {
    if (!eventsState.items || !eventsState.items.length) {
      if (selectedEvent && parseInt(selectedEvent.id, 10) === parseInt(eventId || 0, 10)) {
        return selectedEvent;
      }
      return null;
    }
    var id = parseInt(eventId || 0, 10);
    if (!id) return null;
    return eventsState.items.find(function (ev) { return ev.id === id; }) || (selectedEvent && selectedEvent.id === id ? selectedEvent : null);
  }

  function eventMetaText(eventObj) {
    if (!eventObj) return '';
    var count = parseInt(eventObj.dates_count || 0, 10);
    if (!count && Array.isArray(eventObj.dates)) {
      count = eventObj.dates.length;
    }
    if (count <= 0) return 'No event dates';
    return count === 1 ? '1 date configured' : count + ' dates configured';
  }

  function renderEventCards() {
    var $grid = $('#koopo-event-grid');
    var $empty = $('#koopo-event-empty');
    if (!$grid.length) return;

    var events = Array.isArray(eventsState.items) ? eventsState.items : [];
    if (!events.length) {
      $grid.empty();
      $empty.show();
      renderPagination('#koopo-event-pagination', 1, 1, 'events');
      return;
    }

    var html = events.map(function (ev) {
      var thumb = ev.thumbnail ? '<img class="koopo-event-card__thumb" src="' + escapeHtml(ev.thumbnail) + '" alt="">' : '<span class="koopo-event-card__thumb"></span>';
      return '<button type="button" class="koopo-event-card" data-event-id="' + ev.id + '">' +
        thumb +
        '<span class="koopo-event-card__body">' +
          '<strong class="koopo-event-card__title">' + escapeHtml(ev.title || 'Event') + '</strong>' +
          '<span class="koopo-event-card__meta">' + escapeHtml(eventMetaText(ev)) + '</span>' +
        '</span>' +
      '</button>';
    }).join('');

    $grid.html(html);
    $empty.hide();
    renderPagination('#koopo-event-pagination', eventsState.currentPage, eventsState.totalPages, 'events');
  }

  function setSelectedEvent(eventId) {
    selectedEventId = parseInt(eventId || 0, 10) || 0;
    selectedEvent = getEventById(selectedEventId);

    $('#koopo-event-grid .koopo-event-card').removeClass('is-selected')
      .filter('[data-event-id="' + selectedEventId + '"]').addClass('is-selected');

    if (!selectedEventId || !selectedEvent) {
      $('#koopo-ticket-workspace').hide();
      syncMobileEventMode();
      return;
    }

    $('#koopo-selected-event-title').text(selectedEvent.title || 'Selected Event');
    $('#koopo-selected-event-meta').text(eventMetaText(selectedEvent));
    $('#koopo-ticket-event').val(String(selectedEventId));
    $('#koopo-ticket-workspace').show();
    syncMobileEventMode();

    resetTicketForm();
    renderDatePrices(selectedEventId, {});
    ticketsState.currentPage = 1;
    loadTickets(1);

    if (isMobileViewport()) {
      var workspace = document.getElementById('koopo-ticket-workspace');
      if (workspace && workspace.scrollIntoView) {
        workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
  }

  function renderRows(items) {
    var $body = $('#koopo-ticket-types-body');
    if (!$body.length) return;

    if (!selectedEventId) {
      $body.html('<tr class="koopo-tickets-table__message-row"><td class="koopo-tickets-table__message" colspan="12">Select an event first.</td></tr>');
      renderPagination('#koopo-ticket-types-pagination', 1, 1, 'tickets');
      return;
    }

    if (!items.length) {
      $body.html('<tr class="koopo-tickets-table__message-row"><td class="koopo-tickets-table__message" colspan="12">No ticket types yet.</td></tr>');
      renderPagination('#koopo-ticket-types-pagination', ticketsState.currentPage, ticketsState.totalPages, 'tickets');
      return;
    }

    var rows = items.map(function (item) {
      var eventTitle = item.event_title || '—';
      return '<tr>' +
        '<td data-label="Ticket">' + escapeHtml(item.title || '') + '</td>' +
        '<td data-label="Event">' + escapeHtml(eventTitle) + '</td>' +
        '<td data-label="Price">' + fmtCurrency(item.price) + '</td>' +
        '<td data-label="Capacity">' + (item.capacity || '—') + '</td>' +
        '<td data-label="Status">' + statusBadge(item.status) + '</td>' +
        '<td data-label="Visibility">' + (item.visibility || 'public') + '</td>' +
        '<td data-label="Sales Rule">' + fmtSalesRule(item.sales_mode, item.sales_start, item.sales_end) + '</td>' +
        '<td data-label="SKU">' + (item.sku || '—') + '</td>' +
        '<td data-label="Product">' + (item.product_id ? ('#' + item.product_id) : '—') + '</td>' +
        '<td data-label="Variation">' + (item.variation_id ? ('#' + item.variation_id) : '—') + '</td>' +
        '<td data-label="Max/Order">' + (item.max_per_order ? item.max_per_order : '—') + '</td>' +
        '<td data-label="Actions"><div class="koopo-tickets-table__actions">' +
          '<button class="button koopo-edit-ticket" data-id="' + item.id + '">Edit</button>' +
          '<button class="button koopo-delete-ticket" data-id="' + item.id + '">Delete</button>' +
        '</div></td>' +
      '</tr>';
    }).join('');

    $body.html(rows);
    renderPagination('#koopo-ticket-types-pagination', ticketsState.currentPage, ticketsState.totalPages, 'tickets');
  }

  function loadTickets(page) {
    if (!selectedEventId) {
      renderRows([]);
      return;
    }

    ticketsState.currentPage = page || 1;
    var search = $('#koopo-ticket-filter-search').val() || '';
    var status = $('#koopo-ticket-filter-status').val() || '';
    var visibility = $('#koopo-ticket-filter-visibility').val() || '';
    var query = [
      'event_id=' + encodeURIComponent(selectedEventId),
      'page=' + encodeURIComponent(ticketsState.currentPage),
      'per_page=' + encodeURIComponent(ticketsState.perPage)
    ];

    if (search) query.push('search=' + encodeURIComponent(search));
    if (status) query.push('status=' + encodeURIComponent(status));
    if (visibility) query.push('visibility=' + encodeURIComponent(visibility));

    request('ticket-types?' + query.join('&'), 'GET').done(function (items, _textStatus, xhr) {
      ticketsState.items = items || [];
      ticketsState.currentPage = parseInt(xhr.getResponseHeader('X-WP-Page'), 10) || ticketsState.currentPage;
      ticketsState.totalPages = parseInt(xhr.getResponseHeader('X-WP-TotalPages'), 10) || 1;
      renderRows(ticketsState.items);
    }).fail(function () {
      $('#koopo-ticket-types-body').html('<tr class="koopo-tickets-table__message-row"><td class="koopo-tickets-table__message" colspan="12">Unable to load ticket types.</td></tr>');
      renderPagination('#koopo-ticket-types-pagination', 1, 1, 'tickets');
    });
  }

  function loadEvents(page) {
    eventsState.currentPage = page || 1;
    var endpoint = api.events_endpoint || 'vendor/events';
    var search = $('#koopo-event-filter-search').val() || '';
    var query = [
      'page=' + encodeURIComponent(eventsState.currentPage),
      'per_page=' + encodeURIComponent(eventsState.perPage)
    ];

    if (search) {
      query.push('search=' + encodeURIComponent(search));
    }

    request(endpoint + '?' + query.join('&'), 'GET')
      .done(function (items, _textStatus, xhr) {
        eventsState.items = items || [];
        eventsState.currentPage = parseInt(xhr.getResponseHeader('X-WP-Page'), 10) || eventsState.currentPage;
        eventsState.totalPages = parseInt(xhr.getResponseHeader('X-WP-TotalPages'), 10) || 1;

        if (selectedEventId) {
          var refreshed = getEventById(selectedEventId);
          if (refreshed) {
            selectedEvent = refreshed;
          }
        }

        renderEventCards();
      })
      .fail(function () {
        eventsState.items = [];
        eventsState.totalPages = 1;
        renderEventCards();
      });
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
        entry.schedule_id + '">' + escapeHtml(label) + '</button>';
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
      configureDatePricingStep(false);
      $list.empty();
      $price.val('');
      $('[data-vendor-date-picker]').empty();
      $('[data-vendor-date-times]').empty();
      $('#koopo-ticket-date-select').val('');
      currentDateIndex = {};
      currentCalendar = { year: 0, month: 0, selectedDateKey: '' };
      $wrap.hide();
      return;
    }

    configureDatePricingStep(true);
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
          '<span class="koopo-ticket-date-label">' + escapeHtml(labelIndex[key] || 'Date') + '</span>' +
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

  function toggleCustomDates() {
    var mode = $('#koopo-ticket-sales-mode').val();
    $('.koopo-ticket-custom-dates').toggle(mode === 'custom');
  }

  function setStep(step) {
    currentStep = step;
    $('[data-step-panel]').removeClass('is-active')
      .filter('[data-step-panel="' + step + '"]').addClass('is-active');
    $('[data-step-indicator]').removeClass('is-active')
      .filter('[data-step-indicator="' + step + '"]').addClass('is-active');

    $('#koopo-ticket-step-back').toggle(step > 1);
    $('#koopo-ticket-step-next').toggle(step < totalSteps);
    $('#koopo-ticket-submit').toggle(step === totalSteps);
  }

  function configureDatePricingStep(enabled) {
    totalSteps = enabled ? 3 : 2;
    $('[data-step-indicator="3"], [data-step-panel="3"]').toggle(enabled);

    if (currentStep > totalSteps) {
      currentStep = totalSteps;
    }
    setStep(currentStep);
  }

  function validateStep(step) {
    if (step === 1) {
      var title = ($('#koopo-ticket-title').val() || '').trim();
      var price = parseFloat($('#koopo-ticket-price').val() || 0);
      var capacity = parseInt($('#koopo-ticket-capacity').val() || 0, 10);
      if (!title) {
        showNotice('error', 'Ticket name is required.');
        return false;
      }
      if (isNaN(price) || price < 0) {
        showNotice('error', 'Price must be zero or more.');
        return false;
      }
      if (!$('#koopo-ticket-unlimited').is(':checked') && (isNaN(capacity) || capacity < 0)) {
        showNotice('error', 'Capacity must be zero or more.');
        return false;
      }
    }

    if (step === 2) {
      var salesMode = $('#koopo-ticket-sales-mode').val();
      if (salesMode === 'custom' && (!$('#koopo-ticket-sales-start').val() || !$('#koopo-ticket-sales-end').val())) {
        showNotice('error', 'Sales start and end are required for custom mode.');
        return false;
      }
    }

    clearNotice();
    return true;
  }

  function openModal() {
    if (!selectedEventId) {
      showNotice('error', 'Select an event first.');
      return;
    }
    $('#koopo-ticket-form-modal').show();
    $('body').addClass('koopo-ticket-modal-open');
  }

  function closeModal() {
    $('#koopo-ticket-form-modal').hide();
    $('body').removeClass('koopo-ticket-modal-open');
  }

  function setTicketImage(imageId, imageUrl) {
    $('#koopo-ticket-image-id').val(imageId ? String(imageId) : '');
    var $preview = $('#koopo-ticket-image-preview');
    if (!$preview.length) return;

    if (imageUrl) {
      $preview.css('background-image', 'url("' + imageUrl + '")').addClass('has-image');
    } else {
      $preview.css('background-image', '').removeClass('has-image');
    }
  }

  function bindTicketImagePicker() {
    var frame = null;

    $('#koopo-ticket-image-select').on('click', function (e) {
      e.preventDefault();

      if (!window.wp || !wp.media) {
        showNotice('error', 'Media library is not available.');
        return;
      }

      if (frame) {
        frame.open();
        return;
      }

      frame = wp.media({
        title: 'Select Ticket Image',
        button: { text: 'Use this image' },
        multiple: false,
        library: { type: 'image' }
      });

      frame.on('select', function () {
        var attachment = frame.state().get('selection').first();
        if (!attachment) return;

        var data = attachment.toJSON();
        var url = data.sizes && data.sizes.medium ? data.sizes.medium.url : data.url;
        setTicketImage(data.id || 0, url || '');
      });

      frame.open();
    });

    $('#koopo-ticket-image-remove').on('click', function (e) {
      e.preventDefault();
      setTicketImage(0, '');
    });
  }

  function resetTicketForm() {
    var form = $('#koopo-ticket-create')[0];
    if (form) form.reset();

    $('#koopo-ticket-id').val('');
    $('#koopo-ticket-event').val(selectedEventId ? String(selectedEventId) : '');
    $('#koopo-ticket-submit').text('Create Ticket Type');
    $('#koopo-ticket-modal-title').text('Create Ticket Type');
    setTicketImage(0, '');

    $('#koopo-ticket-unlimited').prop('checked', false);
    $('#koopo-ticket-capacity').prop('disabled', false).val('100');

    currentDatePrices = {};
    renderDatePrices(selectedEventId, currentDatePrices);
    toggleCustomDates();
    setStep(1);
  }

  function bindCreate() {
    $('#koopo-ticket-create').on('submit', function (e) {
      e.preventDefault();
      clearNotice();

      if (!selectedEventId) {
        showNotice('error', 'Select an event first.');
        return;
      }

      if (currentStep !== totalSteps) {
        if (validateStep(currentStep) && currentStep < totalSteps) {
          setStep(currentStep + 1);
        }
        return;
      }

      if (!validateStep(1) || !validateStep(2)) {
        return;
      }

      var payload = {
        title: $('#koopo-ticket-title').val(),
        event_id: selectedEventId,
        price: parseFloat($('#koopo-ticket-price').val() || 0),
        capacity: parseInt($('#koopo-ticket-capacity').val(), 10) || 0,
        unlimited_capacity: $('#koopo-ticket-unlimited').is(':checked') ? 1 : 0,
        status: $('#koopo-ticket-status').val(),
        visibility: $('#koopo-ticket-visibility').val(),
        sales_mode: $('#koopo-ticket-sales-mode').val(),
        sales_start: $('#koopo-ticket-sales-start').val(),
        sales_end: $('#koopo-ticket-sales-end').val(),
        sku: $('#koopo-ticket-sku').val(),
        max_per_order: parseInt($('#koopo-ticket-max').val(), 10) || 0,
        image_id: parseInt($('#koopo-ticket-image-id').val(), 10) || 0
      };

      var datePrices = collectDatePrices();
      if (datePrices !== null) {
        payload.date_prices = datePrices;
      }

      var id = $('#koopo-ticket-id').val();
      var endpoint = id ? ('ticket-types/' + id) : 'ticket-types';
      var $submit = $('#koopo-ticket-submit');
      $submit.prop('disabled', true).addClass('is-loading');

      request(endpoint, 'POST', payload).done(function () {
        resetTicketForm();
        closeModal();
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

        if (item.event_id && parseInt(item.event_id, 10) !== selectedEventId) {
          setSelectedEvent(item.event_id);
        }

        $('#koopo-ticket-id').val(item.id);
        $('#koopo-ticket-event').val(item.event_id || selectedEventId || '');
        $('#koopo-ticket-title').val(item.title);
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
        setTicketImage(item.image_id || 0, item.image_url || '');

        currentDatePrices = item.date_prices || {};
        renderDatePrices(item.event_id || selectedEventId, currentDatePrices);

        $('#koopo-ticket-submit').text('Update Ticket Type');
        $('#koopo-ticket-modal-title').text('Edit Ticket Type');
        toggleCustomDates();
        setStep(1);
        openModal();
      });
    });
  }

  function bindFilters() {
    $('#koopo-ticket-filter-search').on('input', function () {
      loadTickets(1);
    });
    $('#koopo-ticket-filter-status, #koopo-ticket-filter-visibility').on('change', function () {
      loadTickets(1);
    });

    $('#koopo-event-filter-search').on('input', function () {
      window.clearTimeout(eventSearchTimer);
      eventSearchTimer = window.setTimeout(function () {
        selectedEventId = 0;
        selectedEvent = null;
        $('#koopo-ticket-workspace').hide();
        loadEvents(1);
        renderRows([]);
        syncMobileEventMode();
      }, 250);
    });
  }

  function bindModal() {
    $('#koopo-ticket-open-create').on('click', function () {
      clearNotice();
      resetTicketForm();
      openModal();
    });

    $('#koopo-ticket-modal-close, #koopo-ticket-cancel').on('click', function () {
      closeModal();
      resetTicketForm();
    });

    $('#koopo-ticket-form-modal').on('click', function (e) {
      if ($(e.target).is('#koopo-ticket-form-modal')) {
        closeModal();
      }
    });

    $('#koopo-ticket-step-next').on('click', function () {
      if (!validateStep(currentStep)) return;
      if (currentStep < totalSteps) {
        setStep(currentStep + 1);
      }
    });

    $('#koopo-ticket-step-back').on('click', function () {
      if (currentStep > 1) {
        setStep(currentStep - 1);
      }
    });
  }

  function bindEventSelection() {
    $('#koopo-event-grid').on('click', '.koopo-event-card', function () {
      var eventId = parseInt($(this).data('event-id'), 10) || 0;
      if (!eventId) return;
      clearNotice();
      setSelectedEvent(eventId);
    });

    $('#koopo-event-back-mobile').on('click', function () {
      clearNotice();
      setSelectedEvent(0);
      var selector = document.getElementById('koopo-event-selector-card');
      if (selector && selector.scrollIntoView) {
        selector.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  }

  function bindPagination() {
    $(document).on('click', '.koopo-tickets-page, .koopo-tickets-page-nav', function () {
      var page = parseInt($(this).data('page'), 10) || 0;
      var type = $(this).data('page-type');
      if (!page || page < 1) return;

      if (type === 'events') {
        if (page === eventsState.currentPage || page > eventsState.totalPages) return;
        loadEvents(page);
        return;
      }

      if (type === 'tickets') {
        if (page === ticketsState.currentPage || page > ticketsState.totalPages) return;
        loadTickets(page);
      }
    });
  }

  $(function () {
    renderEventCards();
    renderRows([]);
    renderAnalytics(null);
    loadAnalytics();
    syncMobileEventMode();
    loadEvents(1);

    bindCreate();
    bindDelete();
    bindEdit();
    bindFilters();
    bindModal();
    bindTicketImagePicker();
    bindEventSelection();
    bindPagination();

    $(window).on('resize orientationchange', function () {
      syncMobileEventMode();
    });

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
      if (month < 0) {
        month = 11;
        year -= 1;
      }
      if (month > 11) {
        month = 0;
        year += 1;
      }

      currentCalendar.year = year;
      currentCalendar.month = month;
      renderVendorCalendar();

      var prefix = year + '-' + padNumber(month + 1) + '-';
      var keys = Object.keys(currentDateIndex).filter(function (key) {
        return key.indexOf(prefix) === 0;
      }).sort();

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
