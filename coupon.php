/* =========================================================
 * AAKASI COUPON TRACKING
 * ========================================================= */

/**
 * 1. Record coupon usage in user meta when an order is completed.
 */
add_action( 'woocommerce_order_status_completed', 'aakasi_record_used_coupons' );
add_action( 'woocommerce_order_status_processing', 'aakasi_record_used_coupons' );

function aakasi_record_used_coupons( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $user_id = $order->get_customer_id();
    if ( ! $user_id ) {
        return; // Guest order — see note at bottom of this guide
    }

    $used_codes = array_map( 'strtoupper', $order->get_coupon_codes() );
    if ( empty( $used_codes ) ) {
        return;
    }

    $existing = get_user_meta( $user_id, '_aakasi_used_coupons', true );
    if ( ! is_array( $existing ) ) {
        $existing = array();
    }

    $updated = array_unique( array_merge( $existing, $used_codes ) );
    update_user_meta( $user_id, '_aakasi_used_coupons', $updated );
}

/**
 * 2. Helper: get list of coupons this logged-in user must not see again.
 */
function aakasi_get_hidden_coupons_for_user() {
    $hidden = array();

    // Currently applied in cart (session-based, works for guests too)
    if ( function_exists( 'WC' ) && WC()->cart ) {
        $hidden = array_map( 'strtoupper', WC()->cart->get_applied_coupons() );
    }

    // Previously used (logged-in users only)
    if ( is_user_logged_in() ) {
        $used = get_user_meta( get_current_user_id(), '_aakasi_used_coupons', true );
        if ( is_array( $used ) ) {
            $hidden = array_unique( array_merge( $hidden, $used ) );
        }
    }

    return $hidden;
}

/**
 * 3. AJAX endpoint so the coupon strip can refresh live after apply/remove,
 *    without a full page reload (WooCommerce coupon actions are AJAX-based).
 */
add_action( 'wp_ajax_aakasi_get_hidden_coupons', 'aakasi_ajax_get_hidden_coupons' );
add_action( 'wp_ajax_nopriv_aakasi_get_hidden_coupons', 'aakasi_ajax_get_hidden_coupons' );

function aakasi_ajax_get_hidden_coupons() {
    wp_send_json( aakasi_get_hidden_coupons_for_user() );
}

/**
 * 4. Admin UI: show + allow resetting a user's used-coupon list
 *    on their profile page (Users > Edit User).
 */
add_action( 'show_user_profile', 'aakasi_render_used_coupons_admin_field' );
add_action( 'edit_user_profile', 'aakasi_render_used_coupons_admin_field' );

function aakasi_render_used_coupons_admin_field( $user ) {
    if ( ! current_user_can( 'edit_users' ) ) {
        return;
    }

    $used = get_user_meta( $user->ID, '_aakasi_used_coupons', true );
    if ( ! is_array( $used ) ) {
        $used = array();
    }
    ?>
    <h2>Aakasi Coupon Usage</h2>
    <table class="form-table">
        <tr>
            <th><label>Used Coupons</label></th>
            <td>
                <?php if ( empty( $used ) ) : ?>
                    <p>No coupons used yet.</p>
                <?php else : ?>
                    <?php foreach ( $used as $code ) : ?>
                        <label style="display:block; margin-bottom:6px;">
                            <input type="checkbox" name="aakasi_reset_coupons[]" value="<?php echo esc_attr( $code ); ?>">
                            Reset <strong><?php echo esc_html( $code ); ?></strong> (make it available to this user again)
                        </label>
                    <?php endforeach; ?>
                    <p class="description">Tick a box and click "Update User" / "Update Profile" below to reset that coupon for this customer.</p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'aakasi_save_used_coupons_admin_field' );
add_action( 'edit_user_profile_update', 'aakasi_save_used_coupons_admin_field' );

function aakasi_save_used_coupons_admin_field( $user_id ) {
    if ( ! current_user_can( 'edit_users' ) ) {
        return;
    }

    $used = get_user_meta( $user_id, '_aakasi_used_coupons', true );
    if ( ! is_array( $used ) ) {
        $used = array();
    }

    $to_reset = isset( $_POST['aakasi_reset_coupons'] ) ? array_map( 'sanitize_text_field', $_POST['aakasi_reset_coupons'] ) : array();

    if ( ! empty( $to_reset ) ) {
        $used = array_diff( $used, $to_reset );
        update_user_meta( $user_id, '_aakasi_used_coupons', array_values( $used ) );
    }
}

/**
 * 5. Re-sync a user's used-coupon list whenever an order is deleted,
 *    trashed, cancelled, or refunded — works for both legacy post-based
 *    orders AND HPOS (High-Performance Order Storage).
 */

// Status-change hooks (fire the same way regardless of HPOS or legacy)
add_action( 'woocommerce_order_status_cancelled', 'aakasi_resync_used_coupons_for_order' );
add_action( 'woocommerce_order_status_refunded', 'aakasi_resync_used_coupons_for_order' );

// Legacy (post-based) order deletion hooks
add_action( 'wp_trash_post', 'aakasi_resync_used_coupons_on_delete_legacy' );
add_action( 'before_delete_post', 'aakasi_resync_used_coupons_on_delete_legacy' );

function aakasi_resync_used_coupons_on_delete_legacy( $post_id ) {
    if ( get_post_type( $post_id ) !== 'shop_order' ) {
        return;
    }
    aakasi_resync_used_coupons_for_order( $post_id );
}

// HPOS (custom table-based) order deletion/trash hooks
add_action( 'woocommerce_trash_order', 'aakasi_resync_used_coupons_for_order' );
add_action( 'woocommerce_delete_order', 'aakasi_resync_used_coupons_for_order' );
add_action( 'woocommerce_before_delete_order', 'aakasi_resync_used_coupons_for_order' );

function aakasi_resync_used_coupons_for_order( $order_id ) {
    $order = wc_get_order( $order_id );

    // For a fully-deleted order, wc_get_order() may already return false —
    // in that case we can't read its customer_id/coupons, so we need to
    // resync using data captured BEFORE deletion. See note below.
    if ( ! $order ) {
        return;
    }

    $user_id = $order->get_customer_id();
    if ( ! $user_id ) {
        return; // Guest orders aren't tracked in user meta
    }

    aakasi_rebuild_used_coupons_for_user( $user_id );
}

/**
 * Rebuild a user's entire _aakasi_used_coupons list from scratch,
 * based only on their currently valid (non-cancelled/non-deleted) orders.
 */
function aakasi_rebuild_used_coupons_for_user( $user_id ) {
    $valid_statuses = array( 'wc-processing', 'wc-completed' );

    $orders = wc_get_orders( array(
        'limit'       => -1,
        'status'      => $valid_statuses,
        'customer_id' => $user_id,
        'return'      => 'ids',
    ) );

    $used_codes = array();
    foreach ( $orders as $oid ) {
        $o = wc_get_order( $oid );
        if ( ! $o ) {
            continue;
        }
        $codes = array_map( 'strtoupper', $o->get_coupon_codes() );
        $used_codes = array_merge( $used_codes, $codes );
    }

    $used_codes = array_values( array_unique( $used_codes ) );
    update_user_meta( $user_id, '_aakasi_used_coupons', $used_codes );
}
