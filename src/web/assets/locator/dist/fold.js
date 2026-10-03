/**
 * Fold — the front-end store locator.
 *
 * Progressive enhancement, and meant literally: the markup this attaches to already contains the
 * full, correct list of locations, rendered by Twig. With JavaScript off — or broken, or blocked,
 * or still downloading — the page is a working, indexable, linkable directory of shops. This file
 * adds the map, the distance sort and the asynchronous search on top of that.
 *
 * The consequence worth stating: nothing here is allowed to *remove* content on init. A locator
 * that blanks its own list before its first fetch is a locator that is empty for anyone whose
 * fetch fails.
 */
(function(window, document) {
    'use strict';

    var Fold = window.Fold || {};
    window.Fold = Fold;

    /** Map drivers. Each one is the same six methods; the locator below knows no map API at all. */
    Fold.drivers = Fold.drivers || {};

    // ---------------------------------------------------------------------------------------
    // Loading helpers
    // ---------------------------------------------------------------------------------------

    var loaded = {};

    function loadScript(url) {
        if (loaded[url]) {
            return loaded[url];
        }

        loaded[url] = new Promise(function(resolve, reject) {
            var script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.onload = resolve;
            script.onerror = function() {
                reject(new Error('Fold could not load ' + url));
            };
            document.head.appendChild(script);
        });

        return loaded[url];
    }

    function loadStyle(url) {
        if (loaded[url]) {
            return loaded[url];
        }

        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
        loaded[url] = Promise.resolve();

        return loaded[url];
    }

    // ---------------------------------------------------------------------------------------
    // Leaflet driver
    // ---------------------------------------------------------------------------------------

    Fold.drivers.leaflet = {
        load: function(config) {
            return loadStyle(config.leafletCssUrl).then(function() {
                return loadScript(config.leafletJsUrl);
            }).then(function() {
                if (!config.cluster || !config.clusterJsUrl || (window.L && window.L.markerClusterGroup)) {
                    return;
                }

                // Clustering is a plugin onto `window.L`, so it can only load once Leaflet has —
                // wherever Leaflet came from. If it will not load, the map still draws, one pin per
                // shop: losing the clusters is a cosmetic failure, losing the map is not.
                (config.clusterCssUrls || []).forEach(loadStyle);

                return loadScript(config.clusterJsUrl).catch(function(error) {
                    if (window.console) {
                        window.console.warn(error);
                    }
                });
            });
        },

        create: function(element, config) {
            var L = window.L;

            // Leaflet works out where its marker images are by inspecting its own stylesheet URL,
            // which guesses wrong the moment the CSS is served from a published-asset directory
            // with a hashed name. Telling it outright is one line and removes a whole class of
            // "the pins are invisible" reports.
            L.Icon.Default.imagePath = config.leafletImagePath;

            var map = L.map(element, {
                scrollWheelZoom: false,
            }).setView([config.center.lat, config.center.lng], config.zoom);

            L.tileLayer(config.tileUrl, {
                attribution: config.attribution,
                maxZoom: 19,
            }).addTo(map);

            // Scroll-wheel zoom is off until the map is clicked. Otherwise a map that fills the
            // page traps the scroll, which on a phone means the visitor cannot get past it.
            map.once('focus', function() {
                map.scrollWheelZoom.enable();
            });

            var markers = config.cluster && L.markerClusterGroup
                ? L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 50 })
                : L.layerGroup();

            return {
                map: map,
                markers: markers.addTo(map),
                clustered: !!(config.cluster && L.markerClusterGroup),
            };
        },

        setMarkers: function(handle, locations, onSelect) {
            var L = window.L;
            handle.markers.clearLayers();
            handle.byId = {};

            var markers = [];

            locations.forEach(function(location) {
                if (location.lat === null || location.lng === null) {
                    return;
                }

                var options = {
                    title: location.title,
                    alt: location.title,
                };
                var color = safeColor(location.color);

                // A group colour gets a drawn pin the same size and anchor as Leaflet's default
                // image, so coloured and uncoloured groups line up on the same map. No colour
                // keeps Leaflet's own marker, untouched.
                if (color) {
                    options.icon = L.divIcon({
                        className: 'fold-pin',
                        html: pinSvg(color),
                        iconSize: [25, 41],
                        iconAnchor: [12.5, 41],
                        popupAnchor: [0, -34],
                    });
                }

                var marker = L.marker([location.lat, location.lng], options);

                marker.bindPopup(popupHtml(location));
                marker.on('click', function() {
                    onSelect(location.id);
                });

                markers.push(marker);
                handle.byId[location.id] = marker;
            });

            // In bulk when clustering: the cluster group recomputes once rather than per pin.
            if (handle.markers.addLayers) {
                handle.markers.addLayers(markers);
            } else {
                markers.forEach(function(marker) {
                    handle.markers.addLayer(marker);
                });
            }
        },

        focus: function(handle, id) {
            var marker = handle.byId && handle.byId[id];

            if (!marker) {
                return;
            }

            // A pin inside a cluster is not on the map, so it has no popup to open until the
            // cluster has been zoomed apart; the plugin does that, then hands it back.
            if (handle.clustered && handle.markers.zoomToShowLayer) {
                handle.markers.zoomToShowLayer(marker, function() {
                    marker.openPopup();
                });

                return;
            }

            handle.map.setView(marker.getLatLng(), Math.max(handle.map.getZoom(), 14));
            marker.openPopup();
        },

        fitBounds: function(handle, bounds) {
            if (!bounds) {
                return;
            }

            handle.map.fitBounds([[bounds[0], bounds[1]], [bounds[2], bounds[3]]], {
                padding: [40, 40],
                maxZoom: 15,
            });
        },
    };

    // ---------------------------------------------------------------------------------------
    // Google driver
    // ---------------------------------------------------------------------------------------

    Fold.drivers.google = {
        load: function(config) {
            if (window.google && window.google.maps) {
                return Promise.resolve();
            }

            return loadScript('https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(config.apiKey));
        },

        create: function(element, config) {
            var map = new window.google.maps.Map(element, {
                center: { lat: config.center.lat, lng: config.center.lng },
                zoom: config.zoom,
                mapTypeControl: false,
                streetViewControl: false,
            });

            return {
                map: map,
                markers: [],
                info: new window.google.maps.InfoWindow(),
                byId: {},
            };
        },

        setMarkers: function(handle, locations, onSelect) {
            handle.markers.forEach(function(marker) {
                marker.setMap(null);
            });

            handle.markers = [];
            handle.byId = {};

            locations.forEach(function(location) {
                if (location.lat === null || location.lng === null) {
                    return;
                }

                var options = {
                    position: { lat: location.lat, lng: location.lng },
                    map: handle.map,
                    title: location.title,
                };
                var color = safeColor(location.color);

                // A vector symbol, so the colour needs no image and no extra library.
                if (color) {
                    options.icon = {
                        path: PIN_PATH,
                        fillColor: color,
                        fillOpacity: 1,
                        strokeColor: PIN_STROKE,
                        strokeWeight: 1,
                        scale: 1,
                        anchor: new window.google.maps.Point(12.5, 41),
                    };
                }

                var marker = new window.google.maps.Marker(options);

                marker.addListener('click', function() {
                    handle.info.setContent(popupHtml(location));
                    handle.info.open(handle.map, marker);
                    onSelect(location.id);
                });

                handle.markers.push(marker);
                handle.byId[location.id] = marker;
            });
        },

        focus: function(handle, id) {
            var marker = handle.byId[id];

            if (marker) {
                handle.map.panTo(marker.getPosition());
                window.google.maps.event.trigger(marker, 'click');
            }
        },

        fitBounds: function(handle, bounds) {
            if (!bounds) {
                return;
            }

            var box = new window.google.maps.LatLngBounds(
                { lat: bounds[0], lng: bounds[1] },
                { lat: bounds[2], lng: bounds[3] }
            );

            handle.map.fitBounds(box, 40);
        },
    };

    // ---------------------------------------------------------------------------------------
    // Mapbox driver
    // ---------------------------------------------------------------------------------------

    Fold.drivers.mapbox = {
        load: function(config) {
            return loadStyle('https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.css').then(function() {
                return loadScript('https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.js');
            });
        },

        create: function(element, config) {
            window.mapboxgl.accessToken = config.accessToken;

            var map = new window.mapboxgl.Map({
                container: element,
                style: 'mapbox://styles/mapbox/streets-v12',
                center: [config.center.lng, config.center.lat],
                zoom: config.zoom,
            });

            map.addControl(new window.mapboxgl.NavigationControl());

            return { map: map, markers: [], byId: {} };
        },

        setMarkers: function(handle, locations, onSelect) {
            handle.markers.forEach(function(marker) {
                marker.remove();
            });

            handle.markers = [];
            handle.byId = {};

            locations.forEach(function(location) {
                if (location.lat === null || location.lng === null) {
                    return;
                }

                // Mapbox takes coordinates longitude first. Every other map API here takes them
                // latitude first, and getting it wrong puts a shop in Charlotte off the coast of
                // Somalia — which is at least obvious, unlike most coordinate bugs.
                var color = safeColor(location.color);
                var marker = new window.mapboxgl.Marker(color ? { color: color } : {})
                    .setLngLat([location.lng, location.lat])
                    .setPopup(new window.mapboxgl.Popup().setHTML(popupHtml(location)))
                    .addTo(handle.map);

                marker.getElement().addEventListener('click', function() {
                    onSelect(location.id);
                });

                handle.markers.push(marker);
                handle.byId[location.id] = marker;
            });
        },

        focus: function(handle, id) {
            var marker = handle.byId[id];

            if (marker) {
                handle.map.flyTo({ center: marker.getLngLat(), zoom: 14 });
                marker.togglePopup();
            }
        },

        fitBounds: function(handle, bounds) {
            if (!bounds) {
                return;
            }

            handle.map.fitBounds([[bounds[1], bounds[0]], [bounds[3], bounds[2]]], { padding: 40, maxZoom: 15 });
        },
    };

    // ---------------------------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------------------------

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * The group colour, if it is one. The server already normalizes it to `#rrggbb`; checking again
     * here costs nothing and means a hand-built endpoint response cannot inject markup through it.
     */
    function safeColor(value) {
        return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value) ? value : null;
    }

    /** A teardrop pin on a 25×41 box — the size and point of Leaflet's default marker image. */
    var PIN_PATH = 'M12.5 0C5.6 0 0 5.6 0 12.5c0 9.4 12.5 28.5 12.5 28.5S25 21.9 25 12.5C25 5.6 19.4 0 12.5 0z';
    var PIN_STROKE = 'rgba(0,0,0,0.35)';

    function pinSvg(color) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="25" height="41" viewBox="0 0 25 41" aria-hidden="true" focusable="false">'
            + '<path d="' + PIN_PATH + '" fill="' + color + '" stroke="' + PIN_STROKE + '" stroke-width="1"/>'
            + '<circle cx="12.5" cy="12.5" r="4.5" fill="#fff"/>'
            + '</svg>';
    }

    function popupHtml(location) {
        var html = '<strong>' + escapeHtml(location.title) + '</strong>';

        if (location.address) {
            html += '<br>' + escapeHtml(location.address).replace(/\n/g, '<br>');
        }

        if (location.phone) {
            html += '<br><a href="tel:' + escapeHtml(location.phone) + '">' + escapeHtml(location.phone) + '</a>';
        }

        if (location.directionsUrl) {
            html += '<br><a href="' + escapeHtml(location.directionsUrl) + '" rel="noopener" target="_blank">Directions</a>';
        }

        return html;
    }

    function resultHtml(location, strings) {
        var html = '<li class="fold-result" data-fold-id="' + escapeHtml(location.id) + '">';

        html += '<h3 class="fold-result__name">';
        html += location.url
            ? '<a href="' + escapeHtml(location.url) + '">' + escapeHtml(location.title) + '</a>'
            : escapeHtml(location.title);
        html += '</h3>';

        if (location.distance !== null && location.distance !== undefined) {
            html += '<p class="fold-result__distance">' + escapeHtml(location.distance) + ' ' + escapeHtml(strings.unit) + '</p>';
        }

        if (location.address) {
            html += '<p class="fold-result__address">' + escapeHtml(location.address).replace(/\n/g, '<br>') + '</p>';
        }

        if (location.openNow === true) {
            html += '<p class="fold-result__open fold-result__open--yes">' + escapeHtml(strings.open) + '</p>';
        } else if (location.openNow === false) {
            html += '<p class="fold-result__open fold-result__open--no">' + escapeHtml(strings.closed) + '</p>';
        }

        if (typeof location.inStock === 'boolean') {
            html += '<p class="fold-result__stock">' + escapeHtml(
                location.inStock ? strings.inStock : strings.outOfStock
            ) + '</p>';
        }

        html += '<p class="fold-result__links">';

        if (location.phone) {
            html += '<a href="tel:' + escapeHtml(location.phone) + '">' + escapeHtml(location.phone) + '</a> ';
        }

        if (location.directionsUrl) {
            html += '<a href="' + escapeHtml(location.directionsUrl) + '" rel="noopener" target="_blank">' + escapeHtml(strings.directions) + '</a>';
        }

        html += '</p></li>';

        return html;
    }

    // ---------------------------------------------------------------------------------------
    // The locator
    // ---------------------------------------------------------------------------------------

    /**
     * The query-string parameters `craft.fold.locator()` reads on the server. Writing exactly these
     * into the address bar after a search is what makes the URL shareable: whoever opens it gets
     * the same results rendered in Twig, with or without JavaScript.
     */
    var URL_PARAMS = ['q', 'lat', 'lng', 'radius'];

    /**
     * A page has one address bar and the server applies its query string to every locator on the
     * page, so only the first locator writes to it.
     */
    var urlOwner = null;

    function Locator(root) {
        this.root = root;
        this.config = JSON.parse(root.getAttribute('data-fold-config') || '{}');
        this.strings = JSON.parse(root.getAttribute('data-fold-strings') || '{}');
        this.form = root.querySelector('[data-fold-form]');
        this.list = root.querySelector('[data-fold-results]');
        this.status = root.querySelector('[data-fold-status]');
        this.mapElement = root.querySelector('[data-fold-map]');
        this.locateButton = root.querySelector('[data-fold-locate]');
        this.driver = Fold.drivers[this.config.driver] || Fold.drivers.leaflet;
        this.handle = null;
        this.request = 0;

        this.bind();
        this.initHistory();
        this.initMap();
    }

    Locator.prototype.bind = function() {
        var self = this;

        if (this.form) {
            this.form.addEventListener('submit', function(event) {
                event.preventDefault();
                self.search();
            });
        }

        if (this.locateButton) {
            this.locateButton.addEventListener('click', function() {
                self.useBrowserLocation();
            });
        }

        // Delegated, so it keeps working after the list has been replaced by a search.
        if (this.list) {
            this.list.addEventListener('click', function(event) {
                var item = event.target.closest('[data-fold-id]');

                // A click on a link inside a result is the visitor going to that page, not
                // asking the map to pan. Hijacking it would break opening a shop in a new tab.
                if (item && !event.target.closest('a')) {
                    self.focus(item.getAttribute('data-fold-id'));
                }
            });
        }
    };

    /**
     * Keeps the address bar in step with the results.
     *
     * Each search the visitor runs adds a history entry carrying the same `q`/`lat`/`lng`/`radius`
     * the server reads, so the URL can be copied and the back button steps through searches. Going
     * back to the page as it was loaded puts the server-rendered list back exactly as it was —
     * from a copy taken here, not by asking the endpoint to reproduce it.
     */
    Locator.prototype.initHistory = function() {
        var self = this;

        this.syncUrl = !urlOwner
            && this.config.syncUrl !== false
            && !!(window.history && window.history.pushState && window.URLSearchParams);

        if (!this.syncUrl) {
            return;
        }

        urlOwner = this;
        this.initialKey = urlKey(window.location.search);
        this.currentKey = this.initialKey;

        // A copy, read without touching the page.
        this.snapshot = {
            list: this.list ? this.list.innerHTML : null,
            status: this.status ? this.status.textContent : null,
            q: this.fieldValue('q'),
            radius: this.fieldValue('radius'),
        };

        window.addEventListener('popstate', function() {
            self.onPopState();
        });
    };

    Locator.prototype.fieldValue = function(name) {
        var field = this.form && this.form.elements[name];

        return field && typeof field.value === 'string' ? field.value : null;
    };

    Locator.prototype.setFieldValue = function(name, value) {
        var field = this.form && this.form.elements[name];

        if (field && typeof field.value === 'string' && value !== null) {
            field.value = value;
        }
    };

    /** Writes a finished search into the address bar, as a new history entry. */
    Locator.prototype.pushUrl = function(params) {
        // Everything else already in the query string is the site's, and stays.
        var query = new URLSearchParams(window.location.search);

        URL_PARAMS.forEach(function(name) {
            var value = params.get(name);
            query.delete(name);

            if (value !== null && value !== '') {
                query.set(name, value);
            }
        });

        var search = query.toString();
        var key = urlKey(search);

        // The same search again is not a new place to go back to.
        if (key === this.currentKey) {
            return;
        }

        this.currentKey = key;
        window.history.pushState(
            { fold: true },
            '',
            window.location.pathname + (search ? '?' + search : '') + window.location.hash
        );
    };

    Locator.prototype.onPopState = function() {
        var key = urlKey(window.location.search);

        // `popstate` also fires for a change of `#fragment`, which is not a different search.
        if (key === this.currentKey) {
            return;
        }

        this.currentKey = key;

        if (key === this.initialKey) {
            this.restoreSnapshot();

            return;
        }

        var query = new URLSearchParams(window.location.search);
        var extra = {};

        this.setFieldValue('q', query.get('q') || '');
        this.setFieldValue('radius', query.get('radius') || this.snapshot.radius);

        if (query.get('lat') && query.get('lng')) {
            extra.lat = query.get('lat');
            extra.lng = query.get('lng');
        }

        this.search(extra, { fromHistory: true });
    };

    /** Back to the page as the server rendered it. */
    Locator.prototype.restoreSnapshot = function() {
        // Anything still in flight is now stale.
        this.request++;
        this.root.classList.remove('fold--loading');

        if (this.list && this.snapshot.list !== null) {
            this.list.innerHTML = this.snapshot.list;
        }

        if (this.status && this.snapshot.status !== null) {
            this.status.textContent = this.snapshot.status;
        }

        this.setFieldValue('q', this.snapshot.q);
        this.setFieldValue('radius', this.snapshot.radius);
        this.drawDomMarkers();
    };

    /** Markers for whatever results are in the list now, read from the server-rendered DOM. */
    Locator.prototype.drawDomMarkers = function() {
        var self = this;

        if (!this.handle) {
            return;
        }

        var locations = this.locationsFromDom();

        this.driver.setMarkers(this.handle, locations, function(id) {
            self.highlight(id);
        });

        if (locations.length) {
            this.driver.fitBounds(this.handle, this.boundsOf(locations));
        }
    };

    Locator.prototype.initMap = function() {
        var self = this;

        if (!this.mapElement) {
            return;
        }

        this.driver.load(this.config).then(function() {
            self.handle = self.driver.create(self.mapElement, self.config);

            // The server-rendered list is the first set of markers. Drawn from the DOM rather
            // than re-fetched, so the map matches the list the visitor is already reading and the
            // page costs no request to become useful.
            self.drawDomMarkers();

            if (self.config.requestBrowserLocation) {
                self.useBrowserLocation(true);
            }
        }).catch(function(error) {
            // A map that will not load must not take the list with it.
            if (window.console) {
                window.console.warn(error);
            }

            self.mapElement.setAttribute('hidden', 'hidden');
        });
    };

    /** Reads the server-rendered results back out of the DOM. */
    Locator.prototype.locationsFromDom = function() {
        if (!this.list) {
            return [];
        }

        return Array.prototype.map.call(this.list.querySelectorAll('[data-fold-json]'), function(node) {
            try {
                return JSON.parse(node.getAttribute('data-fold-json'));
            } catch (e) {
                return null;
            }
        }).filter(Boolean);
    };

    Locator.prototype.boundsOf = function(locations) {
        var lats = [];
        var lngs = [];

        locations.forEach(function(location) {
            if (location.lat !== null && location.lng !== null) {
                lats.push(location.lat);
                lngs.push(location.lng);
            }
        });

        if (!lats.length) {
            return null;
        }

        return [Math.min.apply(null, lats), Math.min.apply(null, lngs), Math.max.apply(null, lats), Math.max.apply(null, lngs)];
    };

    Locator.prototype.params = function(extra) {
        var params = new URLSearchParams();
        var data = new FormData(this.form);

        data.forEach(function(value, key) {
            if (value !== '') {
                params.append(key, value);
            }
        });

        Object.keys(extra || {}).forEach(function(key) {
            params.set(key, extra[key]);
        });

        return params;
    };

    /**
     * @param {Object} [extra] Parameters that win over the form's fields.
     * @param {Object} [options] `fromHistory: true` for a search replaying a back/forward step,
     *     which must not add a history entry of its own.
     */
    Locator.prototype.search = function(extra, options) {
        var self = this;
        var fromHistory = !!(options && options.fromHistory);
        var params = this.params(extra);

        // Every request carries a sequence number and only the newest one is allowed to write to
        // the page. Without it, a slow search for "London" can land after a fast one for "Leeds"
        // and leave the visitor looking at the wrong city with the right word in the box.
        var ticket = ++this.request;

        this.root.classList.add('fold--loading');
        this.setStatus(this.strings.searching || '');

        fetch(this.config.endpoint + '?' + params.toString(), {
            headers: { Accept: 'application/json' },
        })
            .then(function(response) {
                // Over the per-visitor search limit. Not "no shops found", and not a reason to
                // clear what is on screen: the previous results stay, and the server's own
                // message (translated, with the wait in it) goes in the status line.
                if (response.status === 429) {
                    return response.json().catch(function() {
                        return {};
                    }).then(function(body) {
                        var error = new Error('Fold search rate-limited');
                        error.foldMessage = body && typeof body.message === 'string' ? body.message : null;
                        throw error;
                    });
                }

                if (!response.ok) {
                    throw new Error('Fold search failed: ' + response.status);
                }

                return response.json();
            })
            .then(function(data) {
                if (ticket !== self.request) {
                    return;
                }

                self.render(data);

                // Only once the results are on the page — a failed search leaves both the list
                // and the address bar as they were.
                if (self.syncUrl && !fromHistory) {
                    self.pushUrl(params);
                }
            })
            .catch(function(error) {
                if (ticket !== self.request) {
                    return;
                }

                if (window.console) {
                    window.console.warn(error);
                }

                // `setStatus` writes textContent, so the server's message is shown as text, never
                // parsed as markup.
                self.setStatus(error.foldMessage || self.strings.error || '');
            })
            .finally(function() {
                if (ticket === self.request) {
                    self.root.classList.remove('fold--loading');
                }
            });
    };

    Locator.prototype.render = function(data) {
        var self = this;

        if (this.list) {
            this.list.innerHTML = data.locations.map(function(location) {
                return resultHtml(location, {
                    unit: data.unit,
                    open: self.strings.open,
                    closed: self.strings.closed,
                    directions: self.strings.directions,
                    inStock: self.strings.inStock,
                    outOfStock: self.strings.outOfStock,
                });
            }).join('');
        }

        if (this.handle) {
            this.driver.setMarkers(this.handle, data.locations, function(id) {
                self.highlight(id);
            });
            this.driver.fitBounds(this.handle, data.bounds);
        }

        this.setStatus(this.statusFor(data));
    };

    /**
     * The message under the search box.
     *
     * Three outcomes, not two: a term nobody could place is a different problem from a place with
     * no shops near it, and telling a visitor "no results" when they have merely misspelt their
     * own town is how a locator loses them.
     */
    Locator.prototype.statusFor = function(data) {
        if (data.originNotFound) {
            return (this.strings.notFound || '').replace('{term}', data.term);
        }

        if (!data.count) {
            return this.strings.noResults || '';
        }

        if (data.count < data.total) {
            return (this.strings.someResults || '')
                .replace('{count}', data.count)
                .replace('{total}', data.total);
        }

        return (data.count === 1 ? this.strings.oneResult : this.strings.results || '')
            .replace('{count}', data.count);
    };

    Locator.prototype.setStatus = function(message) {
        if (this.status) {
            this.status.textContent = message;
        }
    };

    Locator.prototype.highlight = function(id) {
        if (!this.list) {
            return;
        }

        Array.prototype.forEach.call(this.list.querySelectorAll('[data-fold-id]'), function(item) {
            item.classList.toggle('fold-result--active', item.getAttribute('data-fold-id') === String(id));
        });

        var active = this.list.querySelector('.fold-result--active');

        if (active && active.scrollIntoView) {
            active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    };

    Locator.prototype.focus = function(id) {
        this.highlight(id);

        if (this.handle) {
            this.driver.focus(this.handle, id);
        }
    };

    Locator.prototype.useBrowserLocation = function(quiet) {
        var self = this;

        if (!navigator.geolocation) {
            if (!quiet) {
                this.setStatus(this.strings.noGeolocation || '');
            }

            return;
        }

        navigator.geolocation.getCurrentPosition(
            function(position) {
                // Rounded to about ten metres. A store locator does not need to know which room
                // the visitor is standing in, and a coarser coordinate is a smaller thing to put
                // in a server log and a better cache key.
                self.search({
                    lat: position.coords.latitude.toFixed(4),
                    lng: position.coords.longitude.toFixed(4),
                    q: '',
                });
            },
            function() {
                if (!quiet) {
                    self.setStatus(self.strings.locationDenied || '');
                }
            },
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 600000 }
        );
    };

    /** The locator's own parameters from a query string, in a fixed order, for comparison. */
    function urlKey(search) {
        var query = new URLSearchParams(search);

        return URL_PARAMS.map(function(name) {
            return name + '=' + (query.get(name) || '');
        }).join('&');
    }

    Fold.init = function(root) {
        if (root.foldLocator) {
            return root.foldLocator;
        }

        root.foldLocator = new Locator(root);

        return root.foldLocator;
    };

    Fold.initAll = function(context) {
        var roots = (context || document).querySelectorAll('[data-fold-locator]');

        Array.prototype.forEach.call(roots, Fold.init);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            Fold.initAll();
        });
    } else {
        Fold.initAll();
    }
})(window, document);
