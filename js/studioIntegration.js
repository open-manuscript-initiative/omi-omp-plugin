(function () {
  'use strict';

  var VERSION = '1.4.1';
  var GLOBAL_KEY = '__OMI_STUDIO_LAUNCHER__';
  var LAUNCHER_ID = 'omi-studio-launcher';
  var VOLUME_ID = 'omi-studio-volume-launcher';
  var volumeRequest = 0;
  var checkedVolume = null;

  if (window[GLOBAL_KEY] && typeof window[GLOBAL_KEY].mount === 'function') {
    window[GLOBAL_KEY].mount();
    return;
  }

  var lastSubmissionId = null;
  var mountScheduled = false;

  function getConfig() {
    return window.OMI_STUDIO_INTEGRATION || null;
  }

  function getSubmissionId() {
    var url = new URL(window.location.href);
    var queryKeys = ['workflowSubmissionId', 'submissionId', 'id'];

    for (var i = 0; i < queryKeys.length; i += 1) {
      var queryValue = url.searchParams.get(queryKeys[i]);
      if (/^[1-9][0-9]*$/.test(queryValue || '')) return queryValue;
    }

    var segments = url.pathname.split('/').filter(Boolean);
    var markers = ['workflow', 'submission', 'reviewer'];

    for (var m = 0; m < markers.length; m += 1) {
      var markerIndex = segments.indexOf(markers[m]);
      if (markerIndex === -1) continue;

      for (var j = markerIndex + 1; j < segments.length; j += 1) {
        if (/^[1-9][0-9]*$/.test(segments[j])) return segments[j];
      }
    }

    return null;
  }

  function getLauncher() {
    return document.getElementById(LAUNCHER_ID);
  }

  function isCurrentLauncher(element) {
    return Boolean(
      element &&
      element.dataset &&
      element.dataset.omiStudioLauncherVersion === VERSION
    );
  }

  function removeLauncher() {
    var existing = getLauncher();
    if (existing) existing.remove();
  }

  function createDirectLaunchUrl(config, submissionId, mode) {
    var endpoint = new URL(config.launchEndpoint, window.location.origin);
    endpoint.searchParams.set('submissionId', submissionId);

    var launchMode = mode || config.mode;
    if (launchMode && launchMode !== 'auto') {
      endpoint.searchParams.set('mode', launchMode);
    }

    endpoint.searchParams.set('redirect', '1');
    endpoint.searchParams.set('_omi', Date.now().toString());
    return endpoint.toString();
  }

  function createLauncher(config, submissionId, volume) {
    var button = document.createElement('button');
    var label = config.label || (
      config.mode === 'review' ? 'Open in Studio for Review' : 'Open in Studio'
    );

    if (volume) label = config.volumeLabel || 'Open complete volume in Studio';
    button.id = volume ? VOLUME_ID : LAUNCHER_ID;
    button.className = 'omi-studio-launcher' + (volume ? ' omi-studio-launcher--volume' : '');
    button.type = 'button';
    button.textContent = label;
    button.setAttribute('aria-label', label);
    button.dataset.omiStudioLauncherVersion = VERSION;
    button.dataset.omiSubmissionId = submissionId;

    button.addEventListener('pointerdown', function (event) {
      event.stopPropagation();
    }, true);

    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (button.disabled) return;

      button.disabled = true;
      button.classList.add('omi-studio-launcher--loading');
      button.setAttribute('aria-busy', 'true');
      button.textContent = config.openingLabel || 'Opening Studio…';
      window.location.assign(createDirectLaunchUrl(config, submissionId, volume ? 'volume' : null));
    }, true);

    document.body.appendChild(button);
    return button;
  }

  function removeVolume() {
    var button = document.getElementById(VOLUME_ID);
    if (button) button.remove();
  }

  function checkVolume(config, submissionId) {
    if (!config.volumeEnabled || !submissionId) {
      volumeRequest += 1;
      checkedVolume = null;
      removeVolume();
      return;
    }
    if (checkedVolume === submissionId) return;
    checkedVolume = submissionId;
    removeVolume();
    var generation = ++volumeRequest;
    var url = new URL(config.launchEndpoint, window.location.origin);
    if (url.origin !== window.location.origin) return;
    url.searchParams.set('submissionId', submissionId);
    url.searchParams.set('mode', 'volume-check');
    url.searchParams.delete('redirect');
    fetch(url.toString(), {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}})
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (generation !== volumeRequest || getSubmissionId() !== submissionId) return;
        if (data && data.status === true && data.content && data.content.eligible === true) {
          createLauncher(config, submissionId, true);
        }
      })
      .catch(function () { /* Fail closed; ordinary manuscript launch stays available. */ });
  }

  function mount() {
    mountScheduled = false;

    var config = getConfig();
    if (!config || !config.launchEndpoint) return;

    var submissionId = getSubmissionId();
    checkVolume(config, submissionId);
    if (!submissionId) {
      lastSubmissionId = null;
      removeLauncher();
      return;
    }

    var existing = getLauncher();
    var existingMatches = isCurrentLauncher(existing) &&
      existing.dataset.omiSubmissionId === submissionId;

    if (existingMatches) {
      lastSubmissionId = submissionId;
      return;
    }

    removeLauncher();
    createLauncher(config, submissionId);
    lastSubmissionId = submissionId;
  }

  function scheduleMount() {
    if (mountScheduled) return;
    mountScheduled = true;
    window.setTimeout(mount, 0);
  }

  window[GLOBAL_KEY] = {
    version: VERSION,
    mount: scheduleMount
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount, {once: true});
  } else {
    mount();
  }

  function refreshVolume() {
    checkedVolume = null;
    scheduleMount();
  }
  window.addEventListener('popstate', refreshVolume);
  window.addEventListener('pageshow', refreshVolume);
  // PKP can advance a workflow in place without changing the submission URL.
  window.setInterval(refreshVolume, 30000);

  var originalPushState = history.pushState;
  history.pushState = function () {
    var result = originalPushState.apply(this, arguments);
    refreshVolume();
    return result;
  };

  var originalReplaceState = history.replaceState;
  history.replaceState = function () {
    var result = originalReplaceState.apply(this, arguments);
    refreshVolume();
    return result;
  };

  var observer = new MutationObserver(function () {
    var submissionId = getSubmissionId();
    var launcher = getLauncher();

    if (
      submissionId !== lastSubmissionId ||
      (submissionId && !isCurrentLauncher(launcher)) ||
      (submissionId && launcher && launcher.dataset.omiSubmissionId !== submissionId)
    ) {
      scheduleMount();
    }
  });

  observer.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
}());
