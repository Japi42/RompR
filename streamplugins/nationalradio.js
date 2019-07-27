var nationalRadioPlugin = {

    loadBigRadio: function() {
        if ($("#bbclist").hasClass('notfilled')) {
            $('i[name="bbclist"]').makeSpinner();
            $("#bbclist").load("streamplugins/02_nationalradio.php?populate=2&country="+prefs.newradiocountry, function() {
                $('i[name="bbclist"]').stopSpinner();
                $('#bbclist').removeClass('notfilled');
                nationalRadioPlugin.setTheThing();
                layoutProcessor.postAlbumActions();
            });
        }
    },

    setTheThing: function() {
    },

    changeradiocountry: function() {
    	prefs.save({newradiocountry: $("#radioselector").val()});
        $('[name="radiosearcher"]').val("");
        nationalRadioPlugin.loadBigRadioHtml('populate=1&country='+prefs.newradiocountry);
    },

    loadBigRadioHtml: function(qstring, callback) {
        debug.log("RADIO","Getting",qstring);
        $('i[name="bbclist"]').makeSpinner();
        $("#bbclist").load("streamplugins/02_nationalradio.php?"+qstring, function() {
        	$('i[name="bbclist"]').stopSpinner();
            nationalRadioPlugin.setTheThing();
            layoutProcessor.postAlbumActions();
        });
    },

    searchBigRadio: function() {
        var term = $('[name="radiosearcher"]').val();
        if (term != '') {
            debug.log("RADIO","Searching For",term);
            nationalRadioPlugin.loadBigRadioHtml('populate=1&country='+prefs.newradiocountry+'&search='+encodeURIComponent(term));
        }
    },

    searchRadioMore: function(element) {
        var page = element.parent().parent().children('input[name="spage"]').val();
        var term = element.parent().parent().children('input[name="term"]').val();
        debug.log("RADIO","Searching For Page",page,"of",term);
        $.get('streamplugins/02_nationalradio.php?populate=3&country='+prefs.newradiocountry+'&page='+page+'&search='+term, function(data) {
            element.parent().parent().remove();
            $('#bbclist').append(data);
            layoutProcessor.postAlbumActions();
        });
    },

    browseRadio: function(element) {
        var page = 0;
        if (element.hasClass('clickradioback')) {
            page = element.parent().parent().children('input[name="prev"]').val();
        } else if (element.hasClass('clickradioforward')) {
            page = element.parent().parent().children('input[name="next"]').val();
        } else if (element.hasClass('clickdirblepager')) {
            page = element.attr('name');
        }
        var url = element.parent().parent().children('input[name="url"]').val();
        debug.log("RADIO","Getting page",page,"from",url);
        nationalRadioPlugin.loadBigRadioHtml('populate=1&country='+prefs.newradiocountry+'&page='+page+'&url='+encodeURIComponent(url));
    },

    handleClick: function(event, clickedElement) {
        if (clickedElement.hasClass("clickradioback")) {
            event.stopImmediatePropagation();
            clickedElement.off('click').makeSpinner();
            nationalRadioPlugin.browseRadio(clickedElement);
        } else if (clickedElement.hasClass("clickradioforward")) {
            event.stopImmediatePropagation();
            clickedElement.off('click').makeSpinner();
            nationalRadioPlugin.browseRadio(clickedElement);
        } else if (clickedElement.hasClass("clickdirblepager")) {
            event.stopImmediatePropagation();
            clickedElement.off('click').makeSpinner();
            nationalRadioPlugin.browseRadio(clickedElement);
        } else if (clickedElement.hasClass("clicksearchmore")) {
            event.stopImmediatePropagation();
            nationalRadioPlugin.searchRadioMore(clickedElement);
        } else if (clickedElement.hasClass('dirblesearch')) {
            nationalRadioPlugin.searchBigRadio();
        }

    }

}

menuOpeners['bbclist'] = nationalRadioPlugin.loadBigRadio;
clickRegistry.addClickHandlers('natradio', nationalRadioPlugin.handleClick);
