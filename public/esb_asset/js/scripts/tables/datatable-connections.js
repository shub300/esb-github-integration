// $((function() {
    

// }));

function LoadConnectionDataTable()
{
    var activeTabData = $("#activeTabData").val();
    var csrf_token1 = $('meta[name="csrf-token"]').attr('content');
    var userIntegId = $('#input_IntegPlateformId').val();
    var fixedUrl = $("#AjaxCallUrl").val();
    var contentServerPath  = $("#contentServerPath").val();
   console.log(fixedUrl+'/connections/'+userIntegId);
    "use strict";
    var e = $(".datatables-connections"),
        r = "../integration/public/esb_asset/";
        var assetPath = contentServerPath+"/public/esb_asset";
    if ("laravel" === $("body").attr("data-framework") && (r = $("body").attr("data-asset-path")), e.length) {
        var connectionsTable = e.DataTable({
            ajax: {
                method : 'get',
                url: fixedUrl+'/connections/'+userIntegId,
            },


            columns: [ 
            //{ data: "id" }, 
            // { data: "id" }, 
            { data: "account_name" }, 
            { data: "status" }, 
            { data: "env_type" }, 
            { data: "updated_at" }, 
            { data: "Actions" }
            ],
            columnDefs: [
            // {
            //     targets: 0,
            //     orderable: !1,
            //     responsivePriority: 3,
            //     render: function(e, a, t, s) {
            //         return '<div class="custom-control custom-checkbox"> <input class="custom-control-input dt-checkboxes" type="checkbox" value="" id="checkbox' + e + '" /><label class="custom-control-label" for="checkbox' + e + '"></label></div>'
            //     },
            //     checkboxes: {
            //         selectAllRender: '<div class="custom-control custom-checkbox"> <input class="custom-control-input" type="checkbox" value="" id="checkboxSelectAll" /><label class="custom-control-label" for="checkboxSelectAll"></label></div>'
            //     }
            // }, 
            // {
            //     targets: 0,
            //     visible: !1
            // }, 
            {
                responsivePriority: 1,
                targets: 1,
                render: function(e, a, t, s) {
                    if(t.status===1)
                    {
                        return '<div class="row"><div style="width:15px;height:15px;background-color:green;border-radius:100%;margin-top:2px"></div>&nbsp;&nbsp;&nbsp;Online</div>';
                    }
                    else
                    {
                        return '<div class="row"><div style="width:15px;height:15px;background-color:gray;border-radius:100%;margin-top:2px"></div>&nbsp;&nbsp;&nbsp;Offline</div>';
                    }
                    
                }
            }, 
            {
                targets: -1,
                title: "Actions",
                orderable: !1,
                render: function(e, a, t, s)    {
                    if(t.status===1)
                    {
                        return '<button type="button" class="btn btn-primary btn-sm disconnect" data-id="'+t.id+'" >Disconnect</button>';
                    }       
                    else
                    {
                        return '<button type="button" class="btn btn-success btn-sm" onClick="connectionAction('+t.id+',1)">Connect</button>';
                    }             
                }
            }
        
        ],

            order: [
                [2, "desc"]
            ],
            dom: '<"card-header border-bottom p-1"<"head-label"><"dt-action-buttons text-right"B>><"d-flex justify-content-between align-items-center mx-0 row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>t<"d-flex justify-content-between mx-0 row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',
            displayLength: 7,
            lengthMenu: [7, 10, 25, 50, 75, 100],

            language: {
                paginate: {
                    previous: "&nbsp;",
                    next: "&nbsp;"
                }
            }
        });
        $("div.head-label").html('<h6 class="mb-0">DataTable with Buttons</h6>')
    }
    
}

$(document).on('click','.disconnect',function(e){
    console.log($(this).attr("data-id"));
 $('#disconnect_modal').modal();
})


$('#disconnect_form').on('submit',function(e){
    e.preventDefault();
   console.log(e);
    //let name = e.val();
    // let email = $('#email').val();
    // let mobile_number = $('#mobile_number').val();
    // let subject = $('#subject').val();
    // let message = $('#message').val();

    // $.ajax({
    //   url: "/contact-form",
    //   type:"POST",
    //   data:{
    //     "_token": "{{ csrf_token() }}",
    //     name:name,
    //     email:email,
    //     mobile_number:mobile_number,
    //     subject:subject,
    //     message:message,
    //   },
    //   success:function(response){
    //     console.log(response);
    //   },
    //  });
    });

