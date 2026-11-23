/* neemveda checkout: delivery location pin on an OpenStreetMap map (Leaflet). */
(function () {
  'use strict';
  function init() {
    var el = document.querySelector('[data-nv-map]');
    if (!el || el.dataset.ready) return;
    if (!window.L) {
      // The map library didn't load (blocked or offline): let the customer order with the address alone.
      el.style.display = 'none';
      var locate = document.querySelector('[data-nv-locate]');
      if (locate) locate.style.display = 'none';
      var noPin = document.querySelector('[data-nv-no-pin]');
      if (noPin) noPin.checked = true;
      var st = document.querySelector('[data-nv-map-status]');
      if (st) st.textContent = 'The map could not load, so we’ll use your address. The courier may call you for directions.';
      return;
    }
    el.dataset.ready = '1';
    var latIn = document.querySelector('[data-nv-lat]');
    var lngIn = document.querySelector('[data-nv-lng]');
    var status = document.querySelector('[data-nv-map-status]');
    var start = latIn.value && lngIn.value ? [parseFloat(latIn.value), parseFloat(lngIn.value)] : [NVC_MAP.lat, NVC_MAP.lng];
    var map = L.map(el, { scrollWheelZoom: false }).setView(start, latIn.value ? 17 : 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    var marker = null;
    function say(t) { if (status) status.textContent = t; }
    function place(latlng, zoom) {
      if (!marker) {
        marker = L.marker(latlng, { draggable: true }).addTo(map);
        marker.on('dragend', function () { save(marker.getLatLng()); });
      } else marker.setLatLng(latlng);
      if (zoom) map.setView(latlng, zoom);
      save(marker.getLatLng());
    }
    function save(ll) {
      latIn.value = ll.lat.toFixed(6);
      lngIn.value = ll.lng.toFixed(6);
      var noPin = document.querySelector('[data-nv-no-pin]');
      if (noPin) noPin.checked = false;
      say('Pinned ✓ (' + latIn.value + ', ' + lngIn.value + ')');
    }
    if (latIn.value && lngIn.value) place(L.latLng(start[0], start[1]));
    map.on('click', function (e) { place(e.latlng); });

    var btn = document.querySelector('[data-nv-locate]');
    if (btn) btn.addEventListener('click', function () {
      if (!navigator.geolocation) { say('Your browser cannot share location. Please tap the map instead.'); return; }
      say('Finding you…');
      navigator.geolocation.getCurrentPosition(function (pos) {
        place(L.latLng(pos.coords.latitude, pos.coords.longitude), 17);
      }, function () {
        say('Could not get your location. Please allow location access, or tap the map.');
      }, { enableHighAccuracy: true, timeout: 12000 });
    });
    setTimeout(function () { map.invalidateSize(); }, 300);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  if (window.jQuery) window.jQuery(document.body).on('updated_checkout', init);
})();
