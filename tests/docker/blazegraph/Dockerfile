FROM openjdk:8
MAINTAINER Mateusz Żółtak <mateusz.zoltak@oeaw.ac.at>

WORKDIR /opt
RUN wget https://sourceforge.net/projects/bigdata/files/bigdata/2.1.4/blazegraph.jar/download &&\
  mv download blazegraph.jar

CMD ["/usr/bin/java", "-Dfile.encoding=UTF-8", "-Dsun.jnu.encoding=UTF-8", "-server", "-Xmx2g", "-XX:MaxHeapFreeRatio=20", "-XX:MinHeapFreeRatio=10", "-XX:GCTimeRatio=20", "-jar", "blazegraph.jar"]
EXPOSE 9999
