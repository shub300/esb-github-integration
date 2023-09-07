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

function is_valid_url(url) {
    return /^(http(s)?:\/\/)?(www\.)?[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,5}(:[0-9]{1,5})?(\/.*)?$/.test(url);
}

function connectSitoo(){
    valid = true;
    $('.err_mgs').hide();
    if(!checkvalue($('#mdlSitoo_site'))){
        showErrorAndFocus($('#mdlSitoo_site'));
        valid = false;
    }
    else{
        var sitoo_site = $('#mdlSitoo_site').val();
    }
    if(!valid){
        return false;
    }

    $('#mdlSitoo_base_url').empty();
    $('#mdlSitoo_api_id').empty();
    $('#mdlSitoo').modal('toggle');
    pdata = new FormData($(".sitoo_connect_form")[0]);
    pdata.append("sitoo_site", sitoo_site);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectSitoo",
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
                $('#p2_conn_section').hide();
                $('#p2_connection_form').empty();
                retrievePlatformAccounts('sitoo', 'destination');
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

$(document.body).on('click', '#btnSubmitSitoo', function() {
    valid = true;
    $('.err_mgs').hide();
    if(!checkvalue($('#sitoo_base_url'))){
        showErrorAndFocus($('#sitoo_base_url'));
        valid = false;
    }
    else if (!is_valid_url($('#sitoo_base_url').val())){
        $('.url_error').html('Invalid url format.');
        showErrorAndFocus($('#sitoo_base_url'));
        valid = false;
    }

    if(!checkvalue($('#sitoo_api_id'))){
        showErrorAndFocus($('#sitoo_api_id'));
        valid = false;
    }
    if(!checkvalue($('#sitoo_password'))){
        showErrorAndFocus($('#sitoo_password'));
        valid = false;
    }

    if(!valid){
        return false;
    }

    pdata = new FormData($(".sitoo_connect_form")[0]);
    showOverlay();
    $.ajax({
        url: baseUrl + "/GetSitooSites",
        data: pdata,
        dataType: "json",
        async: true,
        type: "post",
        processData: false,
        contentType: false,
        success: function (res) {
            hideOverlay();
            if (res.status_code == "1") {
                $('#mdlSitoo_site').empty();
                $('#mdlSitoo_site').append($("<option></option>").attr("value", '').text('-- Select Sitoo Site --'));
                for (let i = 0; i < res.status_text.length; i++) {
                    $('#mdlSitoo_site').append($("<option></option>").attr("value", res.status_text[0].eshopid).text(res.status_text[0].server));
                }
                var sitoo_base_url = $('#sitoo_base_url').val();
                var sitoo_api_id = $('#sitoo_api_id').val();
                var sitoo_password = $('#sitoo_password').val();
                $('#mdlSitoo_base_url').html(sitoo_base_url);
                $('#mdlSitoo_api_id').html(sitoo_api_id);
                $('#mdlSitoo_password').html(sitoo_password);
                $('#mdlSitoo').modal('toggle');
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

});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlSitoo_base_url').empty();
    $('#mdlSitoo_api_id').empty();
    $('#mdlSitoo').modal('toggle');
});