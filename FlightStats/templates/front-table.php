<?php
/**
 * Front-end shortcode template for APTS FlightStats Advanced.
 *
 * Shortcode: [apts_fs_flights]
 *
 * Renders the flight status entry form and a styled arrivals-style list.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="apts-fs-front">
    <form id="apts-fs-front-form">
        <h3>FlightStats – Internal Flight Tracking</h3>
        <p>Enter one or more flight numbers (e.g. FR553, EI521). Separate by comma or new line.</p>

        <textarea id="apts-fs-front-flights" rows="4" placeholder="FR553&#10;EI521"></textarea>

        <p>
            <button type="submit" class="button button-primary">Check Flight Status (FlightStats)</button>
        </p>

        <div id="apts-fs-front-message"></div>
    </form>

    <div class="apts-fs-flight-list" id="apts-fs-front-table" style="display:none;"></div>

    <div class="apts-fs-note">
        Styled to match a clean airline arrivals board. Live FlightStats data and "🛬 Check DAA" budget rules will continue to flow into this layout.
    </div>
</div>
