$("#tbl_staff_list").DataTable({
    processing: true,
    serverSide: true,
    ajax:{
    url: $("#AjaxCallUrl").val()+'/get_staff_members',
    'type': 'POST',
    'data': function(data){
        data.status = $("#status").val();
        data._token = $('meta[name="csrf-token"]').attr('content');
        }
    },
    responsive: true,
    "fnInitComplete": function (oSettings, json) {
        $('[data-toggle="tooltip"]').tooltip();
    },
    columns: [
        {data: 'id', name: 'id', 'visible': true,
            render: function (data, type, row, meta) {
                return meta.row + meta.settings._iDisplayStart + 1;
            }
        },
        {data: 'name', name: 'name', 'visible': true},
        {data: 'email', name: 'email', 'visible': true},
        {data: 'status', name: 'status', 'visible': true, orderable: false},
        {data: 'action', name: 'action', orderable: false,
            render: function(data, type, full, meta){
                return full.action;
            }
        },
    ],
    order: [[0, 'desc']],
    columnDefs: [
        {
            responsivePriority: 1,
            targets: 0
        },
        {
            responsivePriority: 2,
            targets: -1
        }
    ],
    drawCallback: function () {
        $('#tbl_staff_list_paginate ul.pagination').addClass("pagination-sm");


    }
});

$(".dataTables_filter input").attr("placeholder", "Search here...").css({
    width: "300px",
    display: "inline-block"
});

// Function to filter datatable according to status value
$(document).on('change','#status',function(){
    $("#tbl_staff_list").DataTable().ajax.reload();
});

// Functionto call a popup model to display pre deletion message
$(document).on('click','.generate_mdl_staff_delete',function(){
    $rowid = $(this).data('rowid');
    $name = $(this).data('name');
    $('#deactivate_name').text($name);
    $('#user_id').val($rowid);
    $('#mdl_staff_delete').modal();
});

// Function to deactivate a user
$(document).on('click','.deactivate_btn',function(){
    $user_id = $('#user_id').val().trim();
    if(!$user_id){
        errorNotify(res.status_text,'Unexpected error occurred. Row not found Please try again');
        return false;
    }
    showOverlay();
    send_data = {'check_val':0,'update_id':$user_id,"_token": $('meta[name="csrf-token"]').attr('content')};
    $.ajax({
        type:"POST",
        url: $("#AjaxCallUrl").val()+'/delete_staff_member',
        data: send_data,
        dataType: "json",
        success: function(res) {
            hideOverlay();
            if(res.status_code==1){
                successNotify(res.status_text,'Success');
                $('#mdl_staff_delete').modal('hide');
                $('#user_id').val('');
                $("#tbl_staff_list").DataTable().ajax.reload();
            }else if(res.status_code==0){
                errorNotify(res.status_text,'Failed');
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
            } else {
                errorNotify('Unexpected error Please try again.','Failed');
            }
        }
    });
})

