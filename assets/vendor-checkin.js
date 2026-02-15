(function ($) {
  'use strict';

  var api = window.KOOPO_TICKETS_VENDOR || {};
  if (!api.rest || !api.nonce) return;

  var detector = null;
  var stream = null;
  var scanTimer = null;
  var scanCooldownMs = 2200;
  var lastScanCode = '';
  var lastScanAt = 0;
  var verifyInFlight = false;

  function request(path, method, data) {
    var base = api.rest.replace(/\/$/, '');
    var url = base + '/' + path.replace(/^\//, '');
    return $.ajax({
      url: url,
      method: method || 'POST',
      data: data ? JSON.stringify(data) : null,
      contentType: 'application/json',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', api.nonce);
      }
    });
  }

  function renderResult(data, isError) {
    var $out = $('#koopo-ticket-checkin-result');
    if (!$out.length) return;

    if (isError) {
      $out.text(data || 'Unable to verify ticket.').css('color', '#7a1e1e');
      return;
    }

    var parts = [];
    parts.push('Status: ' + data.status);
    parts.push('Attendee: ' + (data.attendee_name || '—'));
    if (data.event_title) {
      parts.push('Event: ' + data.event_title);
    } else if (data.event_id) {
      parts.push('Event ID: ' + data.event_id);
    }
    if (data.schedule_date || data.schedule_time) {
      parts.push('Date/Time: ' + [data.schedule_date, data.schedule_time].filter(Boolean).join(' '));
    }
    if (data.seat) {
      parts.push('Seat: ' + data.seat);
    }

    $out.text(parts.join(' | ')).css('color', '#222');
  }

  function getQueryParam(name) {
    var query = window.location.search || '';
    if (!query) return '';
    var params = new URLSearchParams(query);
    return params.get(name) || '';
  }

  function normalizeTicketCode(value) {
    var raw = String(value || '').trim();
    if (!raw) return '';

    if (raw.indexOf('kt_code=') !== -1) {
      try {
        var url = new URL(raw, window.location.origin);
        var fromParam = url.searchParams.get('kt_code');
        if (fromParam) return String(fromParam).trim();
      } catch (err) {
        var match = raw.match(/[?&]kt_code=([^&#]+)/);
        if (match && match[1]) {
          return decodeURIComponent(match[1]).trim();
        }
      }
    }

    return raw;
  }

  function setScanStatus(message, isError) {
    var $status = $('#koopo-ticket-scan-status');
    if (!$status.length) return;
    $status.text(message || '');
    $status.css('color', isError ? '#7a1e1e' : '#555');
  }

  function clearScanTimer() {
    if (scanTimer) {
      window.clearInterval(scanTimer);
      scanTimer = null;
    }
  }

  function stopScanner() {
    clearScanTimer();

    if (stream && stream.getTracks) {
      stream.getTracks().forEach(function (track) {
        track.stop();
      });
    }

    stream = null;
    detector = null;

    var video = document.getElementById('koopo-ticket-scan-video');
    if (video) {
      video.pause();
      video.srcObject = null;
    }
  }

  function detectFrame() {
    if (!detector || !stream || verifyInFlight) return;
    var video = document.getElementById('koopo-ticket-scan-video');
    if (!video || video.readyState < 2) return;

    detector.detect(video).then(function (codes) {
      if (!codes || !codes.length) return;
      var raw = codes[0].rawValue || '';
      var code = normalizeTicketCode(raw);
      if (!code) return;

      var now = Date.now();
      if (code === lastScanCode && (now - lastScanAt) < scanCooldownMs) return;

      lastScanCode = code;
      lastScanAt = now;
      $('#koopo-ticket-code').val(code);
      setScanStatus('Code detected. Verifying ticket...', false);
      $('#koopo-ticket-checkin').trigger('submit');
    }).catch(function () {
      // ignore intermittent decode errors
    });
  }

  function populateCameraList() {
    var $select = $('#koopo-ticket-camera-select');
    if (!$select.length) return Promise.resolve([]);

    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
      $select.html('<option value="">Camera list unavailable</option>');
      return Promise.resolve([]);
    }

    return navigator.mediaDevices.enumerateDevices().then(function (devices) {
      var cameras = devices.filter(function (device) {
        return device.kind === 'videoinput';
      });

      if (!cameras.length) {
        $select.html('<option value="">No camera found</option>');
        return cameras;
      }

      var options = cameras.map(function (camera, index) {
        var label = camera.label || ('Camera ' + (index + 1));
        return '<option value="' + camera.deviceId + '">' + label + '</option>';
      }).join('');

      $select.html(options);
      return cameras;
    }).catch(function () {
      $select.html('<option value="">Unable to load cameras</option>');
      return [];
    });
  }

  function startScanner(deviceId) {
    if (!window.isSecureContext) {
      setScanStatus('Camera scanning requires HTTPS.', true);
      return;
    }

    if (!('BarcodeDetector' in window)) {
      setScanStatus('QR scanning is not supported in this browser. Use manual code entry.', true);
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setScanStatus('Camera access is not available on this device.', true);
      return;
    }

    stopScanner();

    var constraints = {
      video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }
    };

    navigator.mediaDevices.getUserMedia(constraints).then(function (mediaStream) {
      stream = mediaStream;

      var video = document.getElementById('koopo-ticket-scan-video');
      if (!video) return;
      video.srcObject = mediaStream;
      video.setAttribute('playsinline', 'true');

      return video.play().then(function () {
        try {
          detector = new BarcodeDetector({ formats: ['qr_code'] });
        } catch (err) {
          detector = new BarcodeDetector();
        }

        setScanStatus('Camera active. Point at a ticket QR code.', false);
        scanTimer = window.setInterval(detectFrame, 350);
      });
    }).catch(function (err) {
      setScanStatus('Unable to start camera: ' + (err && err.message ? err.message : 'permission denied'), true);
    });
  }

  function maybePrefillCode() {
    var code = normalizeTicketCode(getQueryParam('kt_code'));
    if (!code) return;

    var $input = $('#koopo-ticket-code');
    if (!$input.length) return;

    $input.val(code);
    $('#koopo-ticket-checkin').trigger('submit');
  }

  $(document).on('submit', '#koopo-ticket-checkin', function (e) {
    e.preventDefault();

    var code = normalizeTicketCode($('#koopo-ticket-code').val());
    if (!code) return;

    $('#koopo-ticket-code').val(code);
    verifyInFlight = true;

    var $btn = $(this).find('button[type="submit"]');
    $btn.prop('disabled', true).addClass('is-loading');

    request('tickets/verify', 'POST', { code: code }).done(function (data) {
      renderResult(data, false);
      setScanStatus('Ticket verified: ' + code, false);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Ticket not found.';
      renderResult(msg, true);
      setScanStatus(msg, true);
    }).always(function () {
      verifyInFlight = false;
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '#koopo-ticket-redeem', function () {
    var code = normalizeTicketCode($('#koopo-ticket-code').val());
    if (!code) return;

    $('#koopo-ticket-code').val(code);
    var $btn = $(this);
    $btn.prop('disabled', true).addClass('is-loading');

    request('tickets/redeem', 'POST', { code: code }).done(function (data) {
      renderResult(data, false);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Unable to redeem.';
      renderResult(msg, true);
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '#koopo-ticket-scan-open', function () {
    var $panel = $('#koopo-ticket-scan-panel');
    if (!$panel.length) return;

    var opening = !$panel.is(':visible');
    $panel.toggle(opening);
    $(this).text(opening ? 'Hide Scanner' : 'Scan QR with Camera');

    if (!opening) {
      stopScanner();
      setScanStatus('', false);
      return;
    }

    populateCameraList().then(function (cameras) {
      var defaultDevice = cameras.length ? cameras[0].deviceId : '';
      startScanner(defaultDevice);
    });
  });

  $(document).on('click', '#koopo-ticket-scan-start', function () {
    var deviceId = $('#koopo-ticket-camera-select').val() || '';
    startScanner(deviceId);
  });

  $(document).on('click', '#koopo-ticket-scan-stop', function () {
    stopScanner();
    setScanStatus('Camera stopped.', false);
  });

  $(document).on('change', '#koopo-ticket-camera-select', function () {
    var deviceId = $(this).val() || '';
    if (deviceId) startScanner(deviceId);
  });

  $(window).on('beforeunload', function () {
    stopScanner();
  });

  $(maybePrefillCode);
})(jQuery);
