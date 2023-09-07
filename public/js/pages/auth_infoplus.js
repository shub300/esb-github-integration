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

function connectInfoPlus(){
    $('#mdlinfoplus_domain').empty();
    $('#mdlInfoplus').modal('toggle');
    pdata = new FormData($(".infoplus_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectInfoplusAuth",
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
                retrievePlatformAccounts('infoplus', 'source');
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

$(document.body).on('click', '#btnSubmitInfoplus', function(){
    valid = true;
    $('.err_mgs').hide();
 

    if(!checkvalue($('#infoplus_api_key')))
    {
        showErrorAndFocus($('#infoplus_api_key'));
        valid = false;
    }

    if(!checkvalue($('#infoplus_domain')))
    {
        showErrorAndFocus($('#infoplus_domain'));
        valid = false;
    }
    else
    {
    //check valid email
    var mailformat = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/;
    if(!$('#infoplus_domain').val().match(mailformat))
    {
      $('.infoplus_domain').html('Invalid domain format.');
      showErrorAndFocus($('#infoplus_domain'));
      valid = false;
    }
    }

    if(!valid)
    {
        return false;
    }

    var mdlinfoplus_domain = $('#infoplus_domain').val();
    $('#mdlinfoplus_domain').html(mdlinfoplus_domain);
    $('#mdlInfoplus').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlinfoplus_domain').empty();
    $('#mdlInfoplus').modal('toggle');
});