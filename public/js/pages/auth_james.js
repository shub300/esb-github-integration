var baseUrl = $('body').attr('data-url');

function showErrorAndFocus(elem){
    if(elem.length){
        elem.next().show();
    }
}

function checkvalue(elem){

    var input = elem.val();
    var regex = /(<([^>]+)>)/ig;
    var validatedInput = input.replace(regex, "");
    if(input != validatedInput){
        return false;
    }

    if(elem.length){
        if(!elem.val().trim()){
            return false;
        }else{
            return true;
        }
    }
}

$(document.body).on('change', '.cred', function() {
    if($(this).val()){
        $(this).next('.err_mgs').hide();
    }else{
        $(this).next('.err_mgs').show();
        return false;
    }
});

$('#btnSubmitJames').click(function(e) {
    valid = true;
    $('.err_mgs').hide();
    if(!checkvalue($('#jamesAccountName'))){
        showErrorAndFocus($('#jamesAccountName'));
        valid = false;
    }

    if(!checkvalue($('#jamesApiKey'))){
        showErrorAndFocus($('#jamesApiKey'));
        valid = false;
    }

    if(!checkvalue($('#jamesChannelApiKey'))){
        showErrorAndFocus($('#jamesChannelApiKey'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    var jamesAccountName = $('#jamesAccountName').val();
    var jamesApiKey = $('#jamesApiKey').val();
    var jamesChannelApiKey = $('#jamesChannelApiKey').val();
    $('#mdlJamesAccountName').html(jamesAccountName);
    $('#mdlJamesApiKey').html(jamesApiKey);
    $('#mdlJamesChannelApiKey').html(jamesChannelApiKey);
    $('#mdlJames').modal('toggle');
});

function connectJames(){
    $('#mdlJamesAccountName').empty();
    $('#mdlJamesApiKey').empty();
    $('#mdlJamesChannelApiKey').empty();
    $('#mdlJames').modal('toggle');
    pdata = new FormData($("#jamesConnectForm")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectJames",
        data: pdata,
        dataType: "json",
        async: true,
        type: "post",
        processData: false,
        contentType: false,
        success: function (res) {
            hideOverlay();
            $('.connect-now').prop('disabled',false);
            if (res.status_code == "1") {
                successNotify(res.status_text,'Success');
                $('#p1_conn_section').hide();
                $('#p1_connection_form').empty();
                retrievePlatformAccounts('jamesandjames', 'source');
            }
            else{
                errorNotify(res.status_text,'Failed');
            }
        },
        error: function (jqXHR) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
            } else {
                errorNotify('Unexpected error Please try again.','Failed');
            }
        }
    });
}

$(document.body).on('click', '.btn-close', function() {
    $('#mdlJamesAccountName').empty();
    $('#mdlJamesApiKey').empty();
    $('#mdlJamesChannelApiKey').empty();
    $('#mdlJames').modal('toggle');
});