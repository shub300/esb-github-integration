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

function connectCSCart(){
    $('#mdlcscart_email').empty();
    $('#mdlCSCart').modal('toggle');
    pdata = new FormData($(".cscart_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectCSCartAuth",
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
                retrievePlatformAccounts('cscart', 'source');
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

$(document.body).on('click', '#btnSubmitCSCart', function(){
    valid = true;
    $('.err_mgs').hide();
    if(!checkvalue($('#cscart_email')))
    {
        showErrorAndFocus($('#cscart_email'));
        valid = false;
    }
    else 
    {
      //check valid email
      var mailformat = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*$/;
      if(!$('#cscart_email').val().match(mailformat))
      {
        $('.cscart_email').html('Invalid email format.');
        showErrorAndFocus($('#cscart_email'));
        valid = false;
      }
    }

    if(!checkvalue($('#cscart_api_key')))
    {
        showErrorAndFocus($('#cscart_api_key'));
        valid = false;
    }

    if(!checkvalue($('#cscart_domain')))
    {
        showErrorAndFocus($('#cscart_domain'));
        valid = false;
    }
    else
    {
    //check valid email
    var mailformat = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/;
    if(!$('#cscart_domain').val().match(mailformat))
    {
      $('.cscart_domain').html('Invalid domain format.');
      showErrorAndFocus($('#cscart_domain'));
      valid = false;
    }
    }

    if(!valid)
    {
        return false;
    }

    var cscart_email = $('#cscart_email').val();
    $('#mdlcscart_email').html(cscart_email);
    $('#mdlCSCart').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#mdlcscart_email').empty();
    $('#mdlCSCart').modal('toggle');
});