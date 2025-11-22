(function($){
    $(function(){

        var $form   = $('#apts-fs-front-form');
        var $ta     = $('#apts-fs-front-flights');
        var $msg    = $('#apts-fs-front-message');
        var $list   = $('#apts-fs-front-table');
        var $button = $form.find('button[type="submit"]');

        if (!$form.length) {
            return;
        }

        function buildMeta(label, value) {
            return $('<div/>').addClass('apts-meta-item').append(
                $('<div/>').addClass('apts-meta-label').text(label),
                $('<div/>').addClass('apts-meta-value').text(value)
            );
        }

        $form.on('submit', function(e){
            e.preventDefault();

            var raw = $ta.val() || '';
            raw = $.trim(raw);

            $msg.text('');
            $list.hide();
            $list.empty();

            if (!raw.length) {
                $msg.text('Please enter at least one flight number.');
                return;
            }

            if (typeof APTS_FS_FRONTEND === 'undefined') {
                $msg.text('Configuration error: front-end settings not found.');
                return;
            }

            var originalLabel = $button.text();
            $button.prop('disabled', true).text('Checking…');

            $.post(
                APTS_FS_FRONTEND.ajax_url,
                {
                    action:  'apts_fs_fetch_flights',
                    nonce:   APTS_FS_FRONTEND.nonce,
                    flights: raw
                }
            ).done(function(response){

                if (!response || !response.success) {
                    var err = 'Failed to fetch flight status.';
                    if (response && response.data && response.data.message) {
                        err = response.data.message;
                    }
                    $msg.text(err);
                    $list.hide();
                    return;
                }

                var data    = response.data || {};
                var flights = data.flights || [];

                if (!flights.length) {
                    $msg.text('No flights returned.');
                    $list.hide();
                    return;
                }

                flights.forEach(function(flt){
                    var flight      = flt.flight      || '';
                    var origin      = flt.origin      || '—';
                    var destination = flt.destination || '—';
                    var statusText  = flt.status      || '🛬 Check DAA';
                    var eta         = flt.eta_utc     || '—';
                    var delay       = flt.delay       || '—';
                    var arrivesIn   = flt.arrives_in  || '—';
                    var rowClass    = flt.row_class   || '';

                    var $card = $('<div/>').addClass('apts-flight-card');
                    if (rowClass) {
                        $card.addClass(rowClass);
                    }

                    var $time = $('<div/>').addClass('apts-flight-time').append(
                        $('<div/>').addClass('apts-time-value').text(eta),
                        $('<div/>').addClass('apts-time-label').text('ETA (UTC)')
                    );

                    var $route = $('<div/>').addClass('apts-flight-route').append(
                        $('<div/>').addClass('apts-city').append(
                            $('<span/>').addClass('apts-city-name').text(origin),
                            $('<span/>').addClass('apts-flight-code').text(flight)
                        ),
                        $('<div/>').addClass('apts-route-arrow').html('&rarr;'),
                        $('<div/>').addClass('apts-city').append(
                            $('<span/>').addClass('apts-city-name').text(destination),
                            $('<span/>').addClass('apts-flight-code').text(arrivesIn === '—' ? '' : ('Arrives in ' + arrivesIn))
                        )
                    );

                    var $meta = $('<div/>').addClass('apts-flight-meta').append(
                        buildMeta('Delay', delay),
                        buildMeta('Arrives In', arrivesIn)
                    );

                    var $status = $('<div/>').addClass('apts-flight-status').append(
                        $('<span/>').addClass('apts-status-pill').text(statusText)
                    );

                    $card.append($time, $route, $meta, $status);
                    $list.append($card);
                });

                var note = flights[0].note || '';
                if (note) {
                    $msg.text(note);
                } else {
                    $msg.text('');
                }

                $list.show();

            }).fail(function(){
                $msg.text('Unable to contact server. Please try again.');
                $list.hide();
            }).always(function(){
                $button.prop('disabled', false).text(originalLabel);
            });
        });

    });
})(jQuery);
