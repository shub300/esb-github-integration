var baseUrl = $('body').attr('data-url');

var contentPath = $('body').attr('data-content-path');
jQuery(document).ready(function($) {
    /* function-1 */
    $("#source_platform, #destination_platform").each(function() {
        MakeSelectPlatform($(this));
    })
});
/* function-2 */
var pop;
var ac_cls_type = '';
CreateInterval();

$('body').on('click', '.activate-flow', function() {
    var id = $(this).data('id');

    $.ajax({
        type: 'POST',
        url: baseUrl + "/connectWorkflow",
        data: {
            '_token': $('meta[name="csrf-token"]').attr('content'),
            'id': id
        },
        beforeSend: function() {
            showOverlay();
        },
        success: function(response) {
            hideOverlay();
            if (response.status_code === 1) {
                window.location.href = response.redirect_url;
            } else {
                toastr.error(response.status_text);
            }

        },
        error: function(jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                toastr.error('Internal error: ' + jqXHR.responseText);
            } else {
                toastr.error('Unexpected error Please try again.');
            }
        }
    });
})

/* function-3 */
$('body').on('click', '.connect-now', function() {
    let dc = $('#destination_platform').val();
    let sc = $('#source_platform').val();

    if (sc == '') {
        source_platform_name = $('#source_platform_id').data('name');
        toastr.error('Please select your ' + source_platform_name + ' account');
        return false;
    }
    if (dc == '') {
        destination_platform_name = $('#destination_platform_id').data('name');
        toastr.error('Please select your ' + destination_platform_name + ' account');
        return false;
    }

    if (sc == '' || dc == '') {
        toastr.error('Please select your source and destination account');
        return false;
    }
    confirmation("Confirm!", "Are you sure you want to save this connection settings?", sc, dc);

})

/* function-4 */
$('body').on('change', '#source_platform', function() {
        $('#p1_conn_section').hide();
        var val = $(this).val();
        var url = $(this).find('option:selected').data('src');
        var auth_type = $(this).find('option:selected').data('auth_type');
        ac_cls_type = 'source-account-btn';

        if (auth_type == "oAuth") {
            if (val == 'add-new' && url) {
                $(this).val('');
                AuthAPI(url);
            }
        } else {
            if (url) {
                showOverlay();
                $.get(url, function(data) {
                    hideOverlay();
                    $('#p1_conn_section').show();
                    $('#p1_connection_form').html(data);
                });
            } else {
                $('#p1_conn_section').hide();
            }
        }
    })
    /* function-5 */
$('body').on('change', '#destination_platform', function() {
        var val = $(this).val();
        var url = $(this).find('option:selected').data('src');
        var auth_type = $(this).find('option:selected').data('auth_type');
        ac_cls_type = 'destination-account-btn';

        if (auth_type == "oAuth") {
            if (val == 'add-new' && url) {
                $(this).val('');
                AuthAPI(url);
            }
        } else {
            if (url) {
                showOverlay();
                $.get(url, function(data) {
                    hideOverlay();
                    $('#p2_conn_section').show();
                    $('#p2_connection_form').html(data);
                });
            } else {
                $('#p2_conn_section').hide();
            }
        }
    })
    /* function-6 */
function AuthAPI(url) {
    pop = null;
    clearInterval(checkConnect);
    CreateInterval();
    pop = window.open(url, 'popup', 'width=600,height=600,scrollbars=no,resizable=no');
}
/* function-7 */

function CreateInterval() {
    let elemThis = this;
    var add_new_flow_url2 = $('.p2_auth_endpoint').attr('data-src');
    var add_new_flow_url1 = $('.p1_auth_endpoint').attr('data-src');
    var add_new_flow_authType2 = $('.p2_auth_endpoint').attr('data-auth_type');
    var add_new_flow_authType1 = $('.p1_auth_endpoint').attr('data-auth_type');

    checkConnect = setInterval(function() {
        if (!pop || !pop.closed) return;
        clearInterval(checkConnect);

        var platform_id = '';
        if (ac_cls_type == 'destination-account-btn') {
            platform_id = $('#destination_platform_id').val();
            platform_name = $('#destination_platform_id').data('name');
            $('.destination-account-btn').text('Configure');
            $('#destination_connected').val(0);
        } else if (ac_cls_type == 'source-account-btn') {
            platform_id = $('#source_platform_id').val();
            platform_name = $('#source_platform_id').data('name');
            $('.source-account-btn').text('Configure');
            $('#source_connected').val(0);
        }

        $.ajax({
            type: 'POST',
            url: baseUrl + "/getConnectedAccountInfo",
            data: {
                '_token': $('meta[name="csrf-token"]').attr('content'),
                platform_id: platform_id
            },
            beforeSend: function() {
                showOverlay();
            },
            success: function(response) {
                hideOverlay();
                if (response.status_code === 1) {
                    //if (response.ac_connected === 1) {
                    if (ac_cls_type == 'destination-account-btn') {

                        for (var i = 0; i < response.ac_connected.length; i++) {
                            response.ac_connected[i].account_name;
                        }

                        var options = '<option value="">Select Account</option>';
                        for (var i = 0; i < response.ac_connected.length; i++) {
                            options += '<option data-icon="' + response.ac_connected[i].platform_image + '" value="' + response.ac_connected[i].id + '">' + response.ac_connected[i].account_name + '</option>';
                        }

                        options += '<option value="" disabled="disabled">---------</option><option data-src="' + add_new_flow_url2 + '" data-auth_type="' + add_new_flow_authType2 + '" value="add-new" class="p2_auth_endpoint">Add New ' + platform_name + ' Account</option>';

                        $("#destination_platform").html(options);
                        // $("#destination_platform option:last").prop("selected", "selected");
                        var second_last_option = $('#destination_platform > option').length - 3;
                        $('#destination_platform option:eq(' + second_last_option + ')').attr('selected', 'selected');

                        MakeSelectPlatform($('#destination_platform'));


                    } else if (ac_cls_type == 'source-account-btn') {
                        // $('.source-account-btn').text('Connected');
                        // $('#source_connected').val(1);

                        var options = '<option value="">Select Account</option>';
                        for (var i = 0; i < response.ac_connected.length; i++) {
                            options += '<option data-icon="' + response.ac_connected[i].platform_image + '" value="' + response.ac_connected[i].id + '">' + response.ac_connected[i].account_name + '</option>';
                        }

                        options += '<option value="" disabled="disabled">---------</option><option data-src="' + add_new_flow_url1 + '" data-auth_type="' + add_new_flow_authType1 + '" value="add-new" class="p1_auth_endpoint">Add New ' + platform_name + ' Account</option>';

                        $("#source_platform").html(options);
                        // $("#source_platform option:last").prop("selected", "selected");
                        var second_last_option = $('#source_platform > option').length - 3;
                        $('#source_platform option:eq(' + second_last_option + ')').attr('selected', 'selected');

                        MakeSelectPlatform($('#source_platform'));

                    }
                    //}
                    // toastr.success(response.status_text);
                } else {
                    toastr.error(response.status_text);
                }
            },

            error: function(jqXHR, textStatus, errorThrown) {
                hideOverlay();
                if (jqXHR.status == 500) {
                    toastr.error('Internal error: ' + jqXHR.responseText);
                } else {
                    toastr.error('Unexpected error Please try again.');
                }
            }
        });
    }, 100);
}
/* function-8 */
function formatRes(e) {
    e.element;
    if ($(e.element).data('icon') == undefined) {
        return "<span class='text'>" + e.text + "</span>";
    } else {
        return "<img src=" + contentPath + $(e.element).data('icon') + "><span class='text'>" + e.text + "</span>";
    }


}
/* function-9 */
function MakeSelectPlatform(e) {

    e.wrap('<div class="position-relative select-with-img"></div>'),
        e.select2({
            dropdownAutoWidth: !0,
            width: "100%",
            minimumResultsForSearch: 0 / 0,
            dropdownParent: e.parent(),
            templateResult: formatRes,
            templateSelection: formatRes,
            placeholder: "Select Account",
            escapeMarkup: function(e) {
                return e
            },
            minimumInputLength: 0
        })
}

function confirmation(title, content, sc, dc) {
    $.confirm({
        title: title,
        icon: 'fa fa-question-circle',

        closeAnimation: 'scale',
        closeIcon: true,
        content: content,
        buttons: {
            'confirm': {
                text: 'Confirm',
                btnClass: 'btn-success',
                action: function() {
                    SaveConnection(sc, dc);
                }
            },
            cancel: function() {

            },
        }
    });
}

function reConfirmation(title, content, retitle, recontent) {
    $.confirm({
        title: title,
        content: content,
        icon: 'fa fa-question-circle',
        animation: 'scale',
        closeAnimation: 'scale',
        opacity: 0.5,
        buttons: {
            'confirm': {
                text: 'Proceed',
                btnClass: 'btn-blue',
                action: function() {
                    $.confirm({
                        title: retitle,
                        content: recontent,
                        icon: 'fa fa-warning',
                        animation: 'scale',
                        closeAnimation: 'zoom',
                        buttons: {
                            confirm: {
                                text: 'Yes, sure!',
                                btnClass: 'btn-orange',
                                action: function() {
                                    $.alert('A very critical action <strong>triggered!</strong>');
                                }
                            },
                            cancel: function() {
                                $.alert('you clicked on <strong>cancel</strong>');
                            }
                        }
                    });
                }
            },
            cancel: function() {
                $.alert('you clicked on <strong>cancel</strong>');
            },
            moreButtons: {
                text: 'something else',
                action: function() {
                    $.alert('you clicked on <strong>something else</strong>');
                }
            },
        }
    });
}
/* save connction */
function SaveConnection(dataobj) {

    //  for (var value of dataobj.values()) {
    //             console.log(value);
    //     }


    var wfid = $('.connectionId').attr('data-id');
    $.ajax({
        type: 'POST',
        url: baseUrl + "/saveConnection",
        data: dataobj,
        processData: false,
        dataType: 'json',
        contentType: false,
        async: true,
        beforeSend: function() {
            showOverlay();
        },
        success: function(response) {
            hideOverlay();
            if (response.status_code === 1) {

                $('.workflow_id').val(response.id);
                if ($('.accoBtn:eq(0)').closest('.card').find('.card-body').is(':visible')) {
                    $('.accoBtn:eq(0)').trigger('click');
                }
                if (!$('.accoBtn:eq(1)').closest('.card').find('.card-body').is(':visible')) { // Open 2nd box
                    $('.accoBtn:eq(1)').trigger('click');
                }
                toastr.success(response.status_text);

                setTimeout(function() {
                    window.location = response.redirect_url;
                }, 300);


            } else {
                toastr.error(response.status_text);
            }

        },
        error: function(jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                toastr.error('Internal error: ' + jqXHR.responseText);
            } else {
                toastr.error('Unexpected error Please try again.');
            }
        }
    });
}

/** Load platform accounts in selectbox after successfully connected account using Basic Auth */
function retrievePlatformAccounts(platform_id, ac_type) {
    var add_new_flow_url2 = $('.p2_auth_endpoint').attr('data-src');
    var add_new_flow_url1 = $('.p1_auth_endpoint').attr('data-src');
    var add_new_flow_authType2 = $('.p2_auth_endpoint').attr('data-auth_type');
    var add_new_flow_authType1 = $('.p1_auth_endpoint').attr('data-auth_type');
    $.ajax({
        type: 'POST',
        url: baseUrl + "/getConnectedAccountInfo",
        data: {
            '_token': $('meta[name="csrf-token"]').attr('content'),
            platform_id: platform_id
        },
        beforeSend: function() {
            showOverlay();
        },
        success: function(response) {
            hideOverlay();
            if (response.status_code === 1) {
                if (ac_type == 'destination') {

                    for (var i = 0; i < response.ac_connected.length; i++) {
                        response.ac_connected[i].account_name;
                    }

                    var options = '<option value="">Select Account</option>';
                    for (var i = 0; i < response.ac_connected.length; i++) {
                        options += '<option data-icon="' + response.ac_connected[i].platform_image + '" value="' + response.ac_connected[i].id + '">' + response.ac_connected[i].account_name + '</option>';
                    }

                    options += '<option value="" disabled="disabled">---------</option><option data-src="' + add_new_flow_url2 + '" data-auth_type="' + add_new_flow_authType2 + '" value="add-new">Add New Account</option>';

                    $("#destination_platform").html(options);

                    var second_last_option = $('#destination_platform > option').length - 3;
                    $('#destination_platform option:eq(' + second_last_option + ')').attr('selected', 'selected');

                    MakeSelectPlatform($('#destination_platform'));

                    //load disconnect btn for  destination
                    let p2_rowid = $("#p2_rowid").val();
                    let destinationAccId = $("#destination_platform").val();
                    if (destinationAccId && destinationAccId != "add-new") {
                        handleDisconnectButton(1, p2_rowid, 'destination');
                    }

                } else if (ac_type == 'source') {
                    // $('.source-account-btn').text('Connected');
                    // $('#source_connected').val(1);

                    var options = '<option value="">Select Account</option>';
                    for (var i = 0; i < response.ac_connected.length; i++) {
                        options += '<option data-icon="' + response.ac_connected[i].platform_image + '" value="' + response.ac_connected[i].id + '">' + response.ac_connected[i].account_name + '</option>';
                    }

                    options += '<option value="" disabled="disabled">---------</option><option data-src="' + add_new_flow_url1 + '" data-auth_type="' + add_new_flow_authType1 + '" value="add-new">Add New Account</option>';

                    $("#source_platform").html(options);
                    // $("#source_platform option:last").prop("selected", "selected");
                    var second_last_option = $('#source_platform > option').length - 3;
                    $('#source_platform option:eq(' + second_last_option + ')').attr('selected', 'selected');

                    //MakeSelectPlatform($('#source_platform'));

                    //load disconnect btn for source
                    let p1_rowid = $("#p1_rowid").val();
                    let sourceAccId = $("#source_platform").val();
                    if (sourceAccId && sourceAccId != "add-new") {
                        handleDisconnectButton(1, p1_rowid, 'source');
                    }

                }
            } else {
                toastr.error(response.status_text);
            }
        },

        error: function(jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                toastr.error('Internal error: ' + jqXHR.responseText);
            } else {
                toastr.error('Unexpected error Please try again.');
            }
        }
    });
}


function handleDisconnectButton(connectStatus, RowId, dynClassId) {
    if (connectStatus > 0) {
        let AccId = $("#" + dynClassId + "_platform").val();
        let AccName = $("#" + dynClassId + "_platform option:selected").text().trim();
        let dynDisconBtn = `<button type="button" class="btn btn-primary btn-sm disconnect" data-id="${AccId}" data-platformid="${RowId}" data-platform_name="${AccName}"><i class="fa fa-chain-broken" aria-hidden="true"></i> Disconnect</button>`;

        $("." + dynClassId + "_disconnect").html(dynDisconBtn);
        $("." + dynClassId + "_disconnect").show();
    } else {
        let dynDisconBtn = '';
        $("." + dynClassId + "_disconnect").html(dynDisconBtn);
        $("." + dynClassId + "_disconnect").hide();
    }
}