// $((function() {

// }));


function LoadIntegrationFlowDataTable()
{
    var csrf_token1 = $('meta[name="csrf-token"]').attr('content');
    var userIntegId = $('#input_IntegPlateformId').val();
    var fixedUrl = $("#AjaxCallUrl").val();
    var contentServerPath  = $("#contentServerPath").val();
    "use strict";
    var e = $(".datatables-flows"),
        r = "../integration/public/esb_asset/";
        var assetPath = contentServerPath+"/public/esb_asset";
    if ("laravel" === $("body").attr("data-framework") && (r = $("body").attr("data-asset-path")), e.length) {
        var integrationFlowTable = e.DataTable({
            //ajax: '{{url("flows_list")}}'
            ajax: {
                method : 'post',
                url: fixedUrl+'/flows_list/'+userIntegId,
            },
            //ajax: r + "data/table-datatable1.json",
            columns: [
            // {
            //     data: "responsive_id"
            // }, 
            // { data: "id" }, 
            { data: "pfwfrID" }, 
            { data: "" }, 
            { data: "last_update" }, 
            { data: "mapping" }, 
            { data: "off_on" },   
            ],
            columnDefs: [
        
            {
                targets: 0,
                visible: !1
            }, {
                targets: 1,
                responsivePriority: 4,
                render: function(e, a, t, s) {
                    var l = t.avatar,
                        o = t.sourceEvent,
                        o1 = t.destinationEvent,
                        n = t.uwfrStatus;
                    if (l) var d = '<img src="' + r + "images/avatars/" + l + '" alt="Avatar" width="32" height="32">';
                    else {
                        var i = ["success", "danger", "warning", "info", "dark", "primary", "secondary"][t.uwfrStatus],
                            c = (o = t.sourceEvent).match(/\b\w/g) || [];
                            c1 = (o1 = t.destinationEvent).match(/\b\w/g) || [];
                       // d = '<span class="avatar-content">' + (c = ((c.shift() || "") + (c.pop() || "")).toUpperCase()) + "</span>"
                       d = '<span class="avatar-content">' + (c = ((c.shift() || "") + (c1.shift() || "")).toUpperCase()) + "</span>"
                    }
                    return '<div class="d-flex justify-content-left align-items-center"><div class="avatar ' + ("" === l ? " bg-light-" + i + " " : "") + ' mr-1">' + d + '</div><div class="d-flex flex-column"><span class="emp_name text-truncate font-weight-bold">' + o + ' <img src="'+ assetPath +'/icons/repeat.svg" alt="icon"> '+ o1 +'</span></div></div>'
                }
            }, {
                responsivePriority: 1,
                targets: 2
            }, 

            {
                targets: 3,
                render: function(e, a, t, s) {
                    //show if user workflow_rule status 1
                    var l = t.uwfrStatus,
                        r = {
                            1: {
                                id : "",
                                class: "",
                                sourceEventType : `'${t.sourceEventType}'`,
                            },
                            0: {
                                id : "",
                                class: "display:none",
                                sourceEventType : ``,
                                
                            },
                            null: {
                                id : "",
                                class: "display:none",
                                sourceEventType : ``,
                                
                            },
                        };
                        //return '<div style="text-align:center"><button type="button" class="btn btn-warning btn-sm" onClick="openMapping('+t.pfwfrID+',1)" style="'+r[l].class+'">Platform Mapping</button><br><button type="button" class="btn btn-light btn-sm" onClick="openMapping('+t.pfwfrID+',2)" style="'+r[l].class+'">warehouse Mapping</button></div>';
                        return void 0 === r[l] ? e : '<a><img src="'+assetPath+'/icons/git-pull-request.svg" onClick="openMapping('+t.pfwfrID+','+r[l].sourceEventType+')" alt="icon" style="'+r[l].class+'"></a>';
                }
            },

            {
                targets: 4,
                render: function(e, a, t, s) {
                    console.log(t.uwfrStatus)
                    var l = t.uwfrStatus,
                        r = {
                            1: {
                                checkStatus : "checked"
                            },
                            0: {
                                checkStatus : ""
                            },
                            null: {
                                checkStatus : ""
                            },
                        };
                        return void 0 === r[l] ? e : '<div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="customSwitch'+t.pfwfrID+'" onClick="switchAction('+t.pfwfrID+','+userIntegId+')" '+ r[l].checkStatus +'><label class="custom-control-label" for="customSwitch'+t.pfwfrID+'"></label></div>'
                }
            },
        
        ],
            

            order: [
                [2, "desc"]
            ],
            dom: '<"card-header border-bottom p-1"<"head-label"><"dt-action-buttons text-right"B>><"d-flex justify-content-between align-items-center mx-0 row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>t<"d-flex justify-content-between mx-0 row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',
            displayLength: 7,
            lengthMenu: [7, 10, 25, 50, 75, 100],
            buttons: [{
                extend: "collection",
                className: "btn btn-outline-secondary dropdown-toggle mr-2",
                text: feather.icons.share.toSvg({
                    class: "font-small-4 mr-50"
                }) + "Export",
                buttons: [{
                    extend: "print",
                    text: feather.icons.printer.toSvg({
                        class: "font-small-4 mr-50"
                    }) + "Print",
                    className: "dropdown-item",
                    exportOptions: {
                        columns: [3, 4, 5, 6, 7]
                    }
                }, {
                    extend: "csv",
                    text: feather.icons["file-text"].toSvg({
                        class: "font-small-4 mr-50"
                    }) + "Csv",
                    className: "dropdown-item",
                    exportOptions: {
                        columns: [3, 4, 5, 6, 7]
                    }
                }, {
                    extend: "excel",
                    text: feather.icons.file.toSvg({
                        class: "font-small-4 mr-50"
                    }) + "Excel",
                    className: "dropdown-item",
                    exportOptions: {
                        columns: [3, 4, 5, 6, 7]
                    }
                }, {
                    extend: "pdf",
                    text: feather.icons.clipboard.toSvg({
                        class: "font-small-4 mr-50"
                    }) + "Pdf",
                    className: "dropdown-item",
                    exportOptions: {
                        columns: [3, 4, 5, 6, 7]
                    }
                }, {
                    extend: "copy",
                    text: feather.icons.copy.toSvg({
                        class: "font-small-4 mr-50"
                    }) + "Copy",
                    className: "dropdown-item",
                    exportOptions: {
                        columns: [3, 4, 5, 6, 7]
                    }
                }],
                init: function(e, a, t) {
                    $(a).removeClass("btn-secondary"), $(a).parent().removeClass("btn-group"), setTimeout((function() {
                        $(a).closest(".dt-buttons").removeClass("btn-group").addClass("d-inline-flex")
                    }), 50)
                }
            }, {
                text: feather.icons.plus.toSvg({
                    class: "mr-50 font-small-4"
                }) + "Add New Record",
                className: "create-new btn btn-primary",
                attr: {
                    "data-toggle": "modal",
                    "data-target": "#modals-slide-in"
                },
                init: function(e, a, t) {
                    $(a).removeClass("btn-secondary")
                }
            }],
            responsive: {
                details: {
                    display: $.fn.dataTable.Responsive.display.modal({
                        header: function(e) {
                            return "Details of " + e.data().full_name
                        }
                    }),
                    type: "column",
                    renderer: function(e, a, t) {
                        var s = $.map(t, (function(e, a) {
                            return console.log(t), "" !== e.title ? '<tr data-dt-row="' + e.rowIndex + '" data-dt-column="' + e.columnIndex + '"><td>' + e.title + ":</td> <td>" + e.data + "</td></tr>" : ""
                        })).join("");
                        return !!s && $('<table class="table"/>').append(s)
                    }
                }
            },
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
