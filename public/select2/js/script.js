// $(function () {
//   $('select').each(function () {
//     $(this).select2({
//       theme: 'bootstrap4',
//       width: $(this).data('width') ? $(this).data('width') : $(this).hasClass('w-100') ? '100%' : 'style',
//       placeholder: $(this).data('placeholder'),
//       allowClear: Boolean($(this).data('allow-clear')),
//       closeOnSelect: !$(this).attr('multiple'),
//     });
//   });

// });


// function makeSelect2(){
//   $('select').each(function () {
//   //unqIdentSource
//   $(this).select2({
//   theme: 'bootstrap4',
//   width: $(this).data('width') ? $(this).data('width') : $(this).hasClass('w-100') ? '100%' : 'style',
//   placeholder: $(this).data('placeholder'),
//   allowClear: Boolean($(this).data('allow-clear')),
//   closeOnSelect: !$(this).attr('multiple'),
//   });

// });
// }


function makeSelect2() {
    const MsValidationArr = JSON.parse($("#MsValidationArr").val());
    $.each(MsValidationArr, function(key, pobjName) {

        $('.' + pobjName + '_sourceSelect').select2({
            theme: 'bootstrap4',
            width: $(this).data('width') ? $(this).data('width') : $(this).hasClass('w-100') ? '100%' : 'style',
            placeholder: $(this).data('placeholder'),
            allowClear: Boolean($(this).data('allow-clear')),
            closeOnSelect: !$(this).attr('multiple'),
        });

        $('.' + pobjName + '_destSelect').select2({
            theme: 'bootstrap4',
            width: $(this).data('width') ? $(this).data('width') : $(this).hasClass('w-100') ? '100%' : 'style',
            placeholder: $(this).data('placeholder'),
            allowClear: Boolean($(this).data('allow-clear')),
            closeOnSelect: !$(this).attr('multiple'),
        });

    })

}